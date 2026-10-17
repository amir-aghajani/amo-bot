<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Controllers\ReturnPage;
use App\Modules\Payments\Drivers\Zarinpal\ZarinpalDriver;
use App\Modules\Payments\Drivers\Zarinpal\ZarinpalGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\MethodSettings;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;
use Tests\Support\FakeZarinpal;

/**
 * Zarinpal, an online gateway that sends the customer's browser back: a checkout requests the payment in Rial — asking
 * Zarinpal to confirm it with the bank by itself — and shows its StartPay page; the browser's return only makes the shop
 * ask (inquiry, then verify for the amount asked) and answers it a page of what came of it, with the way back to the
 * bot; IN_BANK waits, FAILED or REVERSED (or an authority it cannot find) closes, 101 is paid as 100 is, another amount
 * is held for support, a verify that says not paid is asked again; the sandbox is another address, and a payment is
 * asked of the one it was opened on; Zarinpal's fee, when the customer pays it, is in the sum the page asks.
 */
final class ZarinpalPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeZarinpal $zarinpal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->zarinpal = $this->zarinpal();
        $this->customer = $this->customer();
        $this->method = $this->zarinpalMethod();
    }

    public function testItIsAnOnlineGatewayOfAMerchantCodeAndASandboxSwitch(): void
    {
        $driver = new ZarinpalDriver(new Client());
        $described = $driver->describe()->toArray();
        self::assertSame(['zarinpal', 'زرین‌پال'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        $fields = array_column($described['fields'], 'type', 'name');
        self::assertSame(['merchant_id' => 'secret', 'sandbox' => 'toggle'], $fields);
        self::assertNull($driver->summary(['merchant_id' => FakeZarinpal::MERCHANT, 'sandbox' => false]));
        self::assertSame('حالت آزمایشی: پولی جابه‌جا نمی‌شود', $driver->summary(['merchant_id' => FakeZarinpal::MERCHANT, 'sandbox' => true]));
    }

    public function testACheckoutRequestsThePaymentInRialAndShowsItsStartPayPage(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $authority = $this->zarinpal->onlyPayment();
        self::assertSame(ZarinpalGateway::LIVE . ZarinpalGateway::START . $authority, $result->payLink()->url);
        self::assertSame(['50000.00', null], [$result->payLink()->amount, $result->payLink()->fee]);
        self::assertEqualsWithDelta(time() + 15 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);

        $payment = $result->payment()->refresh();
        $request = $this->zarinpal->payments[$authority]['request'];
        self::assertSame([
            'merchant_id' => FakeZarinpal::MERCHANT,
            'amount' => 500000,
            'callback_url' => $this->service(OnlineInvoices::class)->callbackUrl($payment),
            'description' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
            'metadata' => ['order_id' => $this->service(OnlineInvoices::class)->orderId($payment), 'auto_verify' => true],
        ], $request, 'in Rial, Zarinpal asked to confirm it with the bank itself');
        self::assertSame([$authority, PaymentStatus::Pending], [$payment->reference, $payment->status]);
    }

    public function testTheCustomersReturnIsAnsweredAPageOfWhatTheShopFound(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $authority = (string) $payment->reference;

        // Back before paying: the shop never reads `Status` (NOK here), it asks — and the fake says IN_BANK, as of a page
        // still open (what Zarinpal says of one left unpaid is not documented; of a cancelled one, FAILED: see below).
        $page = $this->browse($payment, 'NOK');
        self::assertSame(200, $page->getStatusCode());
        self::assertStringStartsWith('text/html', $page->getHeaderLine('Content-Type'));
        self::assertSame(ReturnPage::POLICY, $page->getHeaderLine('Content-Security-Policy'), 'a policy of its own: no script');
        self::assertStringContainsString('هنوز تایید نشده است', (string) $page->getBody());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->zarinpal->pay($authority);
        $page = $this->browse($payment, 'OK');
        $html = (string) $page->getBody();
        self::assertStringContainsString('پرداخت شما تایید شد', $html);
        self::assertStringContainsString(sprintf("سفارش \u{200E}#%d", $payment->order_id), $html, 'its number after a left-to-right mark: on the right-to-left page it would read «12#»');
        self::assertStringNotContainsString(sprintf('سفارش #%d', $payment->order_id), $html);
        self::assertStringContainsString('href="https://t.me/amo_shop_bot"', $html, 'the way back to the bot');
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, (string) FakeZarinpal::REF_ID], [$payment->status, $payment->reference], 'its bank reference');
        self::assertSame(OrderStatus::Fulfilled, $payment->order->status);
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(500000, $this->zarinpal->callsTo(ZarinpalGateway::VERIFY)[0]['body']['amount'], 'verified for the amount asked');

        // Back again (a refresh): paid already, nothing twice.
        self::assertStringContainsString('پرداخت شما تایید شد', (string) $this->browse($payment, 'OK')->getBody());
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAPaymentNotMadeClosesItsPageAndSaysSo(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->zarinpal->fail((string) $payment->reference);

        self::assertStringContainsString('پرداخت انجام نشد', (string) $this->browse($payment, 'NOK')->getBody());
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        self::assertSame(PaymentService::NOTE_LINK_CLOSED, $payment->note);
        self::assertCount(0, $this->zarinpal->callsTo(ZarinpalGateway::VERIFY), 'nothing to verify');
    }

    public function testAPaymentVerifiedBeforeIsPaidAndAnotherAmountIsHeld(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $authority = (string) $payment->reference;
        $this->zarinpal->pay($authority);
        // Zarinpal confirmed it already (auto_verify, or a verify whose answer was lost): 101.
        $this->zarinpal->payments[$authority]['verified'] = true;
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));

        $other = $this->topUp('30000')->payment()->refresh();
        $this->zarinpal->pay((string) $other->reference);
        $this->zarinpal->payments[(string) $other->reference]['amount'] = 200000;
        try {
            $this->service(OnlinePayments::class)->check($other);
            self::fail('another amount is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertTrue(OnlinePayments::isHeld($other->refresh()));
        self::assertSame(PaymentStatus::Pending, $other->status);
    }

    public function testTheSandboxIsZarinpalsTestAddress(): void
    {
        $this->method = $this->zarinpalMethod('زرین‌پال آزمایشی', sandbox: true);

        $link = $this->topUp('20000')->payLink();

        self::assertStringStartsWith(ZarinpalGateway::SANDBOX . ZarinpalGateway::START . 'S', $link->url);
        self::assertStringStartsWith(ZarinpalGateway::SANDBOX, $this->zarinpal->calls[0]['url']);
    }

    public function testAPaymentIsAskedOfTheHostItWasOpenedOnWhateverTheSwitchSaysNow(): void
    {
        $online = $this->service(OnlinePayments::class);
        $live = $this->topUp('50000')->payment()->refresh();
        $this->zarinpal->pay((string) $live->reference);

        // The owner tries the sandbox out: a live payment is still asked of Zarinpal itself, which has its money.
        $this->switchSandbox(true);
        $this->zarinpal->calls = [];
        self::assertSame(OnlineState::Paid, $online->check($live->refresh()->load('method')));
        self::assertSame(PaymentStatus::Paid, $live->refresh()->status);
        self::assertSame([ZarinpalGateway::LIVE], array_values(array_unique(array_map(static fn(array $call): string => substr($call['url'], 0, strlen(ZarinpalGateway::LIVE)), $this->zarinpal->calls))));

        // And back: a page opened in the sandbox is asked of the sandbox, which knows it.
        $tried = $this->topUp('30000')->payment()->refresh();
        self::assertTrue($tried->invoice['sandbox']);
        $this->switchSandbox(false);
        self::assertSame(OnlineState::Waiting, $online->check($tried->refresh()->load('method')), 'never "no such authority" from the live host');
        self::assertSame(PaymentStatus::Pending, $tried->refresh()->status);
    }

    public function testAReversedPaymentClosesItsPage(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->zarinpal->reverse((string) $payment->reference);

        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$payment->refresh()->status, $payment->note]);
        self::assertCount(0, $this->zarinpal->callsTo(ZarinpalGateway::VERIFY), 'given back: nothing to verify');
    }

    public function testAPaymentRequestZarinpalCannotFindIsClosed(): void
    {
        $gateway = self::gateway(new Response(404, [], (string) json_encode(['data' => [], 'errors' => ['message' => 'manual payment request not found.', 'code' => -55, 'validations' => []]])));

        self::assertSame(OnlineState::Closed, $gateway->check(self::opened())->state);
    }

    public function testAPaymentZarinpalWillNotVerifyAfterAllIsAskedAgainLater(): void
    {
        // Paid, inquiry says; not paid after all, says verify (-51): nothing to settle, nothing to hold — asked again.
        $gateway = self::gateway(
            new Response(200, [], (string) json_encode(['data' => ['status' => 'PAID', 'code' => 100, 'message' => 'Success'], 'errors' => []])),
            new Response(400, [], (string) json_encode(['data' => [], 'errors' => ['message' => 'Session is not valid, session is not active paid try.', 'code' => -51, 'validations' => []]])),
        );

        try {
            $gateway->check(self::opened());
            self::fail('not verified: never paid');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
            self::assertStringContainsString('would not verify authority', $e->detail);
            self::assertStringContainsString('code -51', $e->detail);
        }
    }

    public function testAFeeTheCustomerPaysIsInTheSumThePageAsks(): void
    {
        $this->zarinpal->payerFee = true;

        $result = $this->topUp('50000');

        self::assertSame(['50100.00', '100.00'], [$result->payLink()->amount, $result->payLink()->fee], '1,000 Rial of fee is 100 Toman');
        self::assertSame('50000.00', $result->payment()->amount, 'the order is paid at its price');
    }

    public function testAWrongCodeOrAZarinpalOutOfReachOpensNothing(): void
    {
        $logs = $this->logs();
        $this->method = $this->zarinpalMethod('زرین‌پال', merchant: '00000000-0000-0000-0000-000000000000');

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000')->outcome);
        self::assertTrue($logs->hasWarningThatContains('the merchant code is wrong'));

        $payment = $this->topUp('40000', $this->zarinpalMethod('زرین‌پال ۲'))->payment()->refresh();
        $this->zarinpal->down();
        $page = $this->browse($payment, 'OK');
        self::assertSame(503, $page->getStatusCode());
        self::assertStringContainsString('نتیجه پرداخت هنوز معلوم نیست', (string) $page->getBody());
    }

    public function testAnAddressThatNamesNoPaymentIsAPageForABrowserAndJsonForAServer(): void
    {
        $page = $this->send('GET', '/payments/callback/999/' . str_repeat('0', 40), null, ['Accept' => 'text/html']);
        self::assertSame(404, $page->getStatusCode());
        self::assertStringContainsString('این آدرس پرداختی را نشان نمی‌دهد', (string) $page->getBody());

        $payment = $this->topUp('50000')->payment();
        $call = $this->send('GET', $this->callbackPath($payment));
        self::assertSame(['paid' => false], $this->decode($call), 'a server asking for JSON gets JSON');
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }

    /** The customer's browser sent back by Zarinpal, as it sends it: GET, with the authority and the status in the query. */
    private function browse(Payment $payment, string $status): \Psr\Http\Message\ResponseInterface
    {
        return $this->send('GET', $this->callbackPath($payment) . '?Authority=' . $payment->invoice['authority'] . '&Status=' . $status, null, ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
    }

    private function callbackPath(Payment $payment): string
    {
        return (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH);
    }

    /** The owner turns the method's sandbox on or off. */
    private function switchSandbox(bool $on): void
    {
        $driver = $this->service(GatewayRegistry::class)->find(ZarinpalGateway::key()) ?? throw new \LogicException('Zarinpal is not registered.');
        $this->method->forceFill(['config' => $this->service(MethodSettings::class)->write($driver, ['merchant_id' => FakeZarinpal::MERCHANT, 'sandbox' => $on])])->save();
    }

    /** A live payment opened on Zarinpal, as its invoice keeps it. */
    private static function opened(): Payment
    {
        $authority = 'A' . str_repeat('0', 31) . 'abcd';

        return (new Payment())->forceFill(['amount' => '50000.00', 'link' => ZarinpalGateway::LIVE . ZarinpalGateway::START . $authority, 'invoice' => ['authority' => $authority, 'amount' => 500000, 'fee' => null, 'sandbox' => false]]);
    }

    /** A live gateway whose Zarinpal answers these, in turn. */
    private static function gateway(Response ...$answers): ZarinpalGateway
    {
        return new ZarinpalGateway(FakeZarinpal::MERCHANT, false, new Client(['handler' => HandlerStack::create(new MockHandler($answers)), 'http_errors' => false]));
    }
}
