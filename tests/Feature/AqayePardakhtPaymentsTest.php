<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\AqayePardakht\AqayePardakhtDriver;
use App\Modules\Payments\Drivers\AqayePardakht\AqayePardakhtGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeAqayePardakht;

/**
 * AqayePardakht, a Shaparak gateway: a checkout creates a transaction in Toman, its browser's way back the callback
 * address; the customer is led on to the bank's page from a page on the shop's own address (Shaparak opens it only for
 * the gateway's domain as referrer), which asks nothing; their return only makes the shop verify — 1 or 2 paid, 0 not
 * yet while its page takes money and closed after, -10 held; the pin `sandbox` is its test service.
 */
final class AqayePardakhtPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeAqayePardakht $aqaye;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->aqaye = $this->aqayePardakht();
        $this->customer = $this->customer();
        $this->method = $this->aqayePardakhtMethod();
    }

    public function testItIsAnOnlineGatewayOfAPin(): void
    {
        $driver = new AqayePardakhtDriver(new Client());
        $described = $driver->describe()->toArray();
        self::assertSame(['aqayepardakht', 'آقای پرداخت'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['pin' => 'secret'], array_column($described['fields'], 'type', 'name'));
        self::assertNull($driver->summary(['pin' => FakeAqayePardakht::PIN]));
        self::assertSame('حالت آزمایشی: پولی جابه‌جا نمی‌شود', $driver->summary(['pin' => 'sandbox']));
    }

    public function testACheckoutCreatesATransactionInTomanAndLeadsOnFromTheShopsOwnPage(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $id = $this->aqaye->onlyTransaction();
        $payment = $result->payment()->refresh();
        $invoices = $this->service(OnlineInvoices::class);
        self::assertSame($invoices->callbackUrl($payment) . '?go=1', $result->payLink()->url, 'the shop\'s own page, leading on to the bank\'s');
        self::assertSame(['50000.00', null], [$result->payLink()->amount, $result->payLink()->fee]);
        self::assertEqualsWithDelta(time() + 20 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);
        self::assertSame([
            'pin' => FakeAqayePardakht::PIN,
            'amount' => 50000,
            'callback' => $invoices->callbackUrl($payment),
            'callback_method' => 'GET',
            'invoice_id' => $invoices->orderId($payment),
            'description' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
        ], $this->aqaye->transactions[$id]['body'], 'in Toman, the browser back with the result in the query');
        self::assertSame([$id, AqayePardakhtGateway::START . $id, false], [$payment->reference, $payment->invoice['page'], $payment->invoice['sandbox']]);

        // The shop's page: its sum, and one button to the bank's page that tells the bank the shop's origin — no asking.
        $page = $this->browse($payment, '?go=1');
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString(sprintf("پرداخت سفارش \u{200E}#%d", $payment->order_id), $html, 'its number after a left-to-right mark: on the right-to-left page it would read «12#»');
        self::assertStringContainsString('۵۰٬۰۰۰ تومان', $html);
        self::assertStringContainsString('<a href="' . AqayePardakhtGateway::START . $id . '" referrerpolicy="origin"', $html);
        self::assertStringContainsString('ورود به درگاه پرداخت', $html);
        self::assertCount(0, $this->aqaye->callsTo('/verify'), 'leading on asks nothing');
    }

    public function testTheCustomersReturnOnlyMakesTheShopVerifyAndItIsPaidOnce(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->aqaye->onlyTransaction();

        // Back before paying ("status=0", or a forged "1"): verify says 0 — not yet, its page still open.
        $html = (string) $this->browse($payment, "?transid={$id}&status=1")->getBody();
        self::assertStringContainsString('هنوز تایید نشده است', $html);
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->aqaye->pay($id);
        $html = (string) $this->browse($payment, "?transid={$id}&status=1&tracking_number=123")->getBody();
        self::assertStringContainsString('پرداخت شما تایید شد', $html);
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $id, OrderStatus::Fulfilled], [$payment->status, $payment->reference, $payment->order->status]);
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(['pin' => FakeAqayePardakht::PIN, 'amount' => 50000, 'transid' => $id], $this->aqaye->callsTo('/verify')[1]['body']);

        // Its old «💳 پرداخت» tapped again: the page of what came of it, not the bank's.
        self::assertStringContainsString('پرداخت شما تایید شد', (string) $this->browse($payment, '?go=1')->getBody());
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testVerifiedBeforeIsPaidAndAnotherAmountIsHeld(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->aqaye->onlyTransaction();
        $this->aqaye->pay($id);
        // Verified once already (an answer that never came back): "2".
        $this->aqaye->transactions[$id]['state'] = 'verified';
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));

        $other = $this->topUp('30000', $this->aqayePardakhtMethod('آقای پرداخت ۲'))->payment()->refresh();
        $this->aqaye->pay((string) $other->reference);
        $this->aqaye->transactions[(string) $other->reference]['amount'] = 20000;
        try {
            $this->service(OnlinePayments::class)->check($other);
            self::fail('another amount is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertSame(PaymentStatus::Pending, $other->refresh()->status);
    }

    public function testNotPaidWaitsWhileItsPageIsOpenThenCloses(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->aqaye->fail($this->aqaye->onlyTransaction());

        self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment), 'the customer may still be on the bank\'s page');

        Carbon::setTestNow(now()->addMinutes(AqayePardakhtGateway::PAGE_MINUTES + 1));
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$payment->refresh()->status, $payment->note]);
    }

    public function testTheSandboxsPinIsItsTestService(): void
    {
        $payment = $this->topUp('20000', $this->aqayePardakhtMethod('آقای پرداخت آزمایشی', pin: 'sandbox'))->payment()->refresh();

        self::assertSame([AqayePardakhtGateway::SANDBOX_START . $payment->reference, true], [$payment->invoice['page'], $payment->invoice['sandbox']]);
    }

    public function testAWrongPinOrAnotherDomainOpensNothing(): void
    {
        $logs = $this->logs();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->aqayePardakhtMethod('آقای پرداخت', pin: 'wrong-pin-0000'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('AqayePardakht would not create a transaction (HTTP 422, code -6) — the pin is wrong'));

        $this->config(['app.url' => 'https://other.example']);
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('40000', $this->aqayePardakhtMethod('آقای پرداخت ۲'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('code -15) — the shop\'s address (APP_URL) is not on the domain'));

        // Out of reach: nothing changes, and the gateway is asked again later.
        $this->config(['app.url' => 'https://shop.example']);
        $payment = $this->topUp('30000', $this->aqayePardakhtMethod('آقای پرداخت ۳'))->payment()->refresh();
        $this->aqaye->down();
        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('out of reach');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }

    /** The customer's browser at the payment's callback address, with `$query`. */
    private function browse(Payment $payment, string $query): ResponseInterface
    {
        return $this->send('GET', (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH) . $query, null, ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);
    }
}
