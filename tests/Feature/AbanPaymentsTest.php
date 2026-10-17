<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Controllers\ReturnPage;
use App\Modules\Payments\Drivers\Aban\AbanDriver;
use App\Modules\Payments\Drivers\Aban\AbanGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\OnlineRequest;
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
use Tests\HttpTestCase;
use Tests\Support\FakeAban;

/**
 * AbanGateway, an online gateway for card-to-card payments to the merchant's own card: a checkout opens an invoice in
 * Rial for 30 minutes, calling the shop back and sending the customer back to it, and shows its page, the exact sum it
 * asks (a few Rial more, never credited) and the card; its call — or the customer's return, or the shop's own asking —
 * reads the invoice and claims a paid one once (verify, then 409: paid all the same); paid in part waits, money in — and,
 * expired then, is held for support; expired or cancelled closes, another order or amount is held for support; an
 * invoice no longer pending is never opened
 * as a page; a test key is Aban's test mode, whose invoices a live key does not see.
 */
final class AbanPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeAban $aban;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example', 'telegram.username' => 'amo_shop_bot']);
        $this->telegram();
        $this->aban = $this->aban();
        $this->customer = $this->customer();
        $this->method = $this->abanMethod();
    }

    public function testItIsAnOnlineGatewayOfAKeyAndACardSwitch(): void
    {
        $driver = new AbanDriver(new Client());
        $described = $driver->describe()->toArray();
        self::assertSame(['abangateway', 'آبان گیت وی'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['api_key' => 'secret', 'show_card' => 'toggle'], array_column($described['fields'], 'type', 'name'));
        self::assertNull($driver->summary(['api_key' => FakeAban::KEY, 'show_card' => true]));
        self::assertSame('حالت آزمایشی: پولی جابه‌جا نمی‌شود', $driver->summary(['api_key' => FakeAban::TEST_KEY, 'show_card' => true]));

        // Each key prefix in a Persian sentence set apart: right to left, a bare «ag_test_» reads «_ag_test».
        $words = json_encode($described, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertSame(0, preg_match('/(?<!\x{2068})ag_(live|test)_(?!\x{2069}|…)/u', $words), 'every «ag_live_» and «ag_test_» but the placeholder isolated');
        self::assertStringContainsString("کلید \u{2068}ag_test_\u{2069} حالت آزمایشی است", $words);
    }

    public function testACheckoutOpensAnInvoiceInRialAndShowsItsPageTheExactSumAndTheCard(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $id = $this->aban->onlyInvoice();
        $link = $result->payLink();
        self::assertSame("https://abangateway.ir/pay/{$id}", $link->url);
        self::assertSame(['50001.00', null], [$link->amount, $link->fee], 'the exact sum: 10 Rial more, Aban\'s to match the transfer by');
        self::assertSame([FakeAban::CARD, 'سارا رضایی', 'ملی'], [$link->card?->card, $link->card?->holder, $link->card?->bank]);
        self::assertEqualsWithDelta(time() + 30 * 60, $link->expiresAt?->getTimestamp(), 5);

        $payment = $result->payment()->refresh();
        $invoices = $this->service(OnlineInvoices::class);
        self::assertSame([
            'amount_rial' => 500000,
            'order_id' => $invoices->orderId($payment),
            'description' => sprintf("سفارش \u{200E}#%d", $payment->order_id),
            'expiry_minutes' => AbanGateway::PAGE_MINUTES,
            'callback_url' => $invoices->callbackUrl($payment),
            'return_url' => $invoices->callbackUrl($payment),
        ], $this->aban->invoices[$id]['request'], 'in Rial, calling the shop back and sending the customer back to it');
        self::assertSame(FakeAban::KEY, $this->aban->calls[0]['key'], 'its key as a Bearer token');
        self::assertSame([$id, PaymentStatus::Pending, '50000.00'], [$payment->reference, $payment->status, $payment->amount]);
    }

    public function testItsCallOnlyMakesTheShopAskAndAPaidInvoiceIsClaimedOnce(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->aban->onlyInvoice();
        $callback = $this->callbackPath($payment);
        $event = ['event' => 'invoice.paid', 'invoice_id' => $id, 'order_id' => 'not-trusted', 'amount_rial' => 1, 'status' => 'paid'];

        // A call that says it was paid, before anything was: Aban is asked, and says it is pending.
        self::assertSame(['paid' => false], $this->decode($this->send('POST', $callback, $event)));
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertCount(0, $this->aban->callsTo('/verify'), 'nothing to claim yet');

        $this->aban->pay($id);
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, $event)));
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $id], [$payment->status, $payment->reference]);
        self::assertSame(OrderStatus::Fulfilled, $payment->order->status);
        self::assertSame('50000.00', $this->customer->balance(), 'credited at the order\'s price, never the few Rial more');
        self::assertCount(1, $this->aban->callsTo('/verify'));

        // Aban calls again (it retries what it thinks lost): paid already — Aban is not asked, nothing twice.
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, $event)));
        self::assertCount(1, $this->aban->callsTo('/verify'));
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count(), 'told once');
    }

    public function testAClaimLostOnTheWayIsPaidAllTheSame(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->aban->onlyInvoice();
        $this->aban->pay($id);
        // The shop claimed it, and its answer never came back.
        $this->aban->invoices[$id]['verified'] = true;

        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function testTheCustomersReturnIsAnsweredAPageOfWhatTheShopFound(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->aban->pay($this->aban->onlyInvoice());

        $page = $this->send('GET', $this->callbackPath($payment), null, ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);

        self::assertSame(200, $page->getStatusCode());
        self::assertSame(ReturnPage::POLICY, $page->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('پرداخت شما تایید شد', (string) $page->getBody());
        self::assertStringContainsString('href="https://t.me/amo_shop_bot"', (string) $page->getBody());
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function testPaidInPartWaitsThenExpiredIsHeldForSupportAndCancelledCloses(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->aban->onlyInvoice();
        $online = $this->service(OnlinePayments::class);

        $this->aban->payPart($id);
        self::assertSame(OnlineState::Waiting, $online->check($payment), 'money came in part: never given up');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->aban->expire($id);
        try {
            $online->check($payment);
            self::fail('held for support');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_CLOSED_WITH_MONEY], [$payment->refresh()->status, $payment->note]);
        self::assertTrue(OnlinePayments::awaitsSupport($payment), 'a part of the money came: support looks at once, the order paid no other way meanwhile');

        $other = $this->topUp('40000', $this->abanMethod('آبان گیت وی ۲'))->payment()->refresh();
        $this->aban->cancel((string) $other->reference);
        self::assertSame(OnlineState::Closed, $online->check($other));
    }

    public function testPaidInPartIsMoneyIn(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $gateway = $this->service(GatewayRegistry::class)->forPayment($payment);
        self::assertInstanceOf(AbanGateway::class, $gateway);

        self::assertSame([OnlineState::Waiting, false], [$gateway->check($payment)->state, $gateway->check($payment)->moneyIn], 'pending: nothing in yet');
        $this->aban->payPart($this->aban->onlyInvoice());
        $verdict = $gateway->check($payment);
        self::assertSame([OnlineState::Waiting, true], [$verdict->state, $verdict->moneyIn], 'a part of a split sum came: Aban keeps it');
    }

    public function testAnInvoiceNoLongerPendingIsNoPageToSendTheCustomerTo(): void
    {
        $gateway = $this->service(GatewayRegistry::class)->forMethod($this->method);
        self::assertInstanceOf(AbanGateway::class, $gateway);
        $request = static fn(int $id): OnlineRequest => new OnlineRequest(orderId: "amo-{$id}-0000000000", amount: 50000, callbackUrl: "https://shop.example/payments/callback/{$id}/x", description: "سفارش #{$id}");

        $first = $gateway->open(new Payment(), $request(1));
        self::assertSame($first->reference, $gateway->open(new Payment(), $request(1))->reference, 'the same order id while pending: the same invoice, its page still good');

        foreach (['pay', 'expire', 'cancel'] as $i => $end) {
            $opened = $gateway->open(new Payment(), $request($i + 2));
            $this->aban->{$end}((string) $opened->reference);
            try {
                $gateway->open(new Payment(), $request($i + 2));
                self::fail("an invoice after {$end}() is no page to pay");
            } catch (GatewayUnavailableException $e) {
                self::assertStringContainsString('opened an invoice it did not describe', $e->detail, $end);
            }
        }
    }

    public function testAnInvoiceOpenedWithAKeyOfTheOtherModeIsSaidSo(): void
    {
        $method = $this->abanMethod('آبان گیت وی آزمایشی', key: FakeAban::TEST_KEY);
        $payment = $this->topUp('50000', $method)->payment()->refresh();
        // The owner puts the live key in, the test over.
        $driver = $this->service(GatewayRegistry::class)->find(AbanGateway::key()) ?? throw new \LogicException('AbanGateway is not registered.');
        $method->forceFill(['config' => $this->service(MethodSettings::class)->write($driver, ['api_key' => FakeAban::KEY, 'show_card' => true])])->save();

        $gateway = $this->service(GatewayRegistry::class)->forPayment($payment->refresh()->load('method'));
        self::assertInstanceOf(AbanGateway::class, $gateway);
        try {
            $gateway->check($payment);
            self::fail('a live key sees no test invoice');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
            self::assertStringContainsString('(HTTP 404, invoice_not_found): Invoice not found — it was opened with a test key and the method\'s key is now a live one: only a test key sees it', $e->detail);
        }
    }

    public function testAnInvoiceOfAnotherOrderOrAmountIsHeldAndNeverClaimed(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->aban->onlyInvoice();
        $this->aban->pay($id);
        $this->aban->invoices[$id]['amount'] = 400000;

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('another amount is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertTrue(OnlinePayments::isHeld($payment->refresh()));
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertCount(0, $this->aban->callsTo('/verify'), 'not claimed: support decides');
    }

    public function testTheCardIsLeftOutWhenTheMerchantSaysSoOrTheSumIsSplit(): void
    {
        $this->method = $this->abanMethod('آبان گیت وی بی کارت', showCard: false);
        self::assertNull($this->topUp('50000')->payLink()->card);

        $gateway = $this->service(GatewayRegistry::class)->forMethod($this->abanMethod('آبان گیت وی ۳'));
        self::assertInstanceOf(AbanGateway::class, $gateway);
        $large = $gateway->open(new Payment(), new OnlineRequest(orderId: 'amo-9-0000000000', amount: AbanGateway::CARD_MOST + 1, callbackUrl: 'https://shop.example/payments/callback/9/x', description: 'سفارش #9'));
        self::assertNull($large->details['card'], 'above a card\'s ceiling Aban splits it into parts on its page');
    }

    public function testAShopNotOnHttpsIsAskedAboutAndNeverCalledOrReturnedTo(): void
    {
        $this->config(['app.url' => 'http://localhost:8080']);

        self::assertSame(CheckoutOutcome::Link, $this->topUp('50000')->outcome);
        $request = $this->aban->invoices[$this->aban->onlyInvoice()]['request'];
        self::assertArrayNotHasKey('callback_url', $request, 'Aban takes only an https address the internet reaches');
        self::assertArrayNotHasKey('return_url', $request);
    }

    public function testATestKeyIsAbansTestMode(): void
    {
        $payment = $this->topUp('50000', $this->abanMethod('آبان گیت وی آزمایشی', key: FakeAban::TEST_KEY))->payment()->refresh();

        self::assertTrue($payment->invoice['test']);
        self::assertSame(FakeAban::TEST_KEY, $this->aban->calls[0]['key']);
    }

    public function testAWrongKeyAnEmptyFeeWalletOrAbanOutOfReachOpensNothingAndChangesNothing(): void
    {
        $logs = $this->logs();
        $wrong = 'ag_live_' . str_repeat('a', 48);

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->abanMethod('آبان گیت وی', key: $wrong))->outcome);
        self::assertTrue($logs->hasWarningThatContains('AbanGateway would not open an invoice (HTTP 401): Invalid bearer token — the API key is wrong or was revoked'));

        $this->aban->feeWallet = false;
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->abanMethod('آبان گیت وی ۲'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 402, insufficient_fee_wallet): Fee wallet balance is not enough to open this invoice — the fee wallet at Aban is short'));
        $this->aban->feeWallet = true;

        $payment = $this->topUp('40000', $this->abanMethod('آبان گیت وی ۳'))->payment()->refresh();
        $this->aban->down();
        $this->expectException(GatewayUnavailableException::class);
        try {
            $this->service(OnlinePayments::class)->check($payment);
        } finally {
            self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
            self::assertFalse(OnlinePayments::isHeld($payment));
        }
    }

    private function topUp(string $amount, ?PaymentMethod $method = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);

        return $this->service(Checkout::class)->pay($this->customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, fn(?OrderKey $key): Order => $orders->openTopUp($this->customer, (int) $amount, $key));
    }

    private function callbackPath(Payment $payment): string
    {
        return (string) parse_url($this->service(OnlineInvoices::class)->callbackUrl($payment), PHP_URL_PATH);
    }
}
