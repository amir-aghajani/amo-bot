<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Security\Encrypter;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Drivers\AtlasPay\AtlasPayDriver;
use App\Modules\Payments\Drivers\AtlasPay\AtlasPayGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\HttpTestCase;
use Tests\Support\FakeAtlasPay;

/**
 * AtlasPay, an online gateway paid in its Telegram mini app: a checkout opens an order in Toman for the customer's
 * Telegram account and shows the mini app's link and the sum AtlasPay asks (its markup and last digit apart, never
 * credited; a sum below the order's is refused); its call to the shop — or the shop's own asking — settles a confirmed
 * or settled order; one the customer paid short and AtlasPay took all the same, or an answer not saying the shop's order
 * and sum, is held for support; rejected or expired closes; a receipt or an underpayment in review waits, money in — and,
 * rejected then, is held for support; the
 * card, when AtlasPay gives it, is shown while the page takes money and kept encrypted.
 */
final class AtlasPayPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeAtlasPay $atlasPay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->telegram();
        $this->atlasPay = $this->atlasPay();
        $this->customer = $this->customer();
        $this->method = $this->atlasPayMethod();
    }

    public function testItIsAnOnlineGatewayOfAnApiKey(): void
    {
        $driver = new AtlasPayDriver(new Client(), $this->service(Encrypter::class));
        $described = $driver->describe()->toArray();
        self::assertSame(['atlaspay', 'اطلس‌پی'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['api_key' => 'secret'], array_column($described['fields'], 'type', 'name'));
        self::assertNull($driver->summary(['api_key' => FakeAtlasPay::KEY]));
        self::assertNotNull($this->service(GatewayRegistry::class)->find(AtlasPayGateway::key()));
    }

    public function testACheckoutOpensAnOrderForTheCustomerAndShowsTheMiniAppAndTheSumAsked(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $id = $this->atlasPay->onlyOrder();
        $order = $this->atlasPay->orders[$id];
        self::assertSame("https://t.me/atlaspaybot/pay?startapp=order_{$id}_{$order['tracking']}", $result->payLink()->url);
        self::assertSame(['50007.00', '7.00'], [$result->payLink()->amount, $result->payLink()->fee], 'its markup and last digit, apart');
        self::assertNull($result->payLink()->card, 'no card without its direct payment info');
        self::assertEqualsWithDelta(time() + 20 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);

        $payment = $result->payment()->refresh();
        $invoices = $this->service(OnlineInvoices::class);
        self::assertSame([
            'merchantOrderRef' => $invoices->orderId($payment),
            'baseAmountToman' => 50000,
            'customerTelegramId' => self::TELEGRAM_ID,
            'webhookUrl' => $invoices->callbackUrl($payment),
        ], $order['request'], 'in Toman, the customer\'s alone, calling the shop back');
        self::assertSame(FakeAtlasPay::KEY, $this->atlasPay->calls[0]['key']);
        self::assertSame([$order['tracking'], PaymentStatus::Pending, '50000.00'], [$payment->reference, $payment->status, $payment->amount], 'told by its tracking code, never its id');
        self::assertSame($id, $payment->invoice['order_id']);
    }

    public function testItsCallOnlyMakesTheShopAskAndAConfirmedOrderSettlesOnce(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->atlasPay->onlyOrder();
        $callback = $this->callbackPath($payment);
        $call = static fn(string $event, string $status): array => ['event' => $event, 'orderId' => $id, 'merchantOrderRef' => 'not-trusted', 'totalAmountToman' => 1, 'status' => $status, 'timestamp' => '2026-10-15T10:00:00.000Z'];

        // A call that says it was confirmed, before anything was: AtlasPay is asked, and says it awaits the transfer.
        self::assertSame(['paid' => false], $this->decode($this->send('POST', $callback, $call('order.confirmed', 'confirmed'))));
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertCount(1, $this->atlasPay->callsTo("/orders/{$id}/verify"));

        $this->atlasPay->pay($id);
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, $call('order.confirmed', 'confirmed'))));
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $this->atlasPay->orders[$id]['tracking']], [$payment->status, $payment->reference]);
        self::assertSame(OrderStatus::Fulfilled, $payment->order->status);
        self::assertSame('50000.00', $this->customer->balance(), 'credited at the order\'s price, never what AtlasPay added');

        // Called again, or asked by the task: paid, nothing twice.
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, $call('order.confirmed', 'settled'))));
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment->refresh()));
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count(), 'told once');
    }

    public function testAnOrderPaidShortAndTakenAllTheSameIsHeldForSupport(): void
    {
        $logs = $this->logs();
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->atlasPay->onlyOrder();
        $this->atlasPay->payShort($id, 30000);

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('a payment short of its sum is never delivered by itself');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertTrue(OnlinePayments::isHeld($payment->refresh()));
        self::assertSame([PaymentStatus::Pending, PaymentService::NOTE_HELD], [$payment->status, $payment->note]);
        self::assertSame(OrderStatus::Pending, $payment->order->status);
        self::assertSame('0.00', $this->customer->balance());
        self::assertTrue($logs->hasErrorThatContains('(30000 of 50007 Toman)'));
    }

    public function testAnAnswerForAnotherOrderOrASmallerSumIsHeld(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->atlasPay->onlyOrder();
        $this->atlasPay->pay($id);
        $this->atlasPay->orders[$id]['ref'] = 'amo-1-0000000000';

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('another order is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testAnAnswerThatDoesNotSayItsOrderOrSumIsHeld(): void
    {
        $payment = self::opened();
        $said = [
            'no reference' => ['totalAmountToman' => 50007],
            'no sum' => ['merchantOrderRef' => 'amo-1-abc'],
            'a sum in words' => ['merchantOrderRef' => 'amo-1-abc', 'totalAmountToman' => '50007'],
            'a reference as a number' => ['merchantOrderRef' => 1, 'totalAmountToman' => 50007],
            'a smaller sum' => ['merchantOrderRef' => 'amo-1-abc', 'totalAmountToman' => 50006],
        ];
        foreach ($said as $case => $answer) {
            try {
                $this->verified($answer)->check($payment);
                self::fail("{$case}: never this payment");
            } catch (GatewayUnavailableException $e) {
                self::assertTrue($e->held, $case);
                self::assertStringContainsString('for another order or a smaller sum', $e->detail, $case);
            }
        }

        self::assertSame(OnlineState::Paid, $this->verified(['merchantOrderRef' => 'amo-1-abc', 'totalAmountToman' => 50007])->check($payment)->state, 'its order, its sum: paid');
    }

    public function testATotalBelowTheOrdersIsRefusedAtOpening(): void
    {
        $gateway = new AtlasPayGateway(FakeAtlasPay::KEY, self::client(new Response(201, [], (string) json_encode([
            'orderId' => 58, 'trackingCode' => 'abcd1234', 'totalAmountToman' => 40000,
            'paymentDeadlineAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 1200), 'customerStartLink' => 'https://t.me/atlaspaybot?start=x',
        ]))), $this->service(Encrypter::class));

        try {
            $gateway->open(new Payment(), new OnlineRequest('amo-1-abc', 50000, 'https://shop.example/payments/callback/1/s', 'سفارش #1'));
            self::fail('40,000 asked for a 50,000 order would be paid for less than its price');
        } catch (GatewayUnavailableException $e) {
            self::assertStringContainsString('opened an order it did not describe', $e->detail);
        }
    }

    public function testASettledOrderIsPaidAndOneTakenShortIsHeldSettledAsWell(): void
    {
        $online = $this->service(OnlinePayments::class);
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->atlasPay->onlyOrder();
        $this->atlasPay->pay($id);
        $this->atlasPay->settle($id);
        self::assertSame(OnlineState::Paid, $online->check($payment));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);

        $short = $this->topUp('40000', $this->atlasPayMethod('اطلس‌پی ۲'))->payment()->refresh();
        $other = (int) $short->invoice['order_id'];
        $this->atlasPay->payShort($other, 30000);
        $this->atlasPay->settle($other);
        try {
            $online->check($short);
            self::fail('settled to the merchant, but for less: support decides');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertSame(PaymentStatus::Pending, $short->refresh()->status);
    }

    public function testAReceiptOrAnUnderpaymentInReviewIsMoneyIn(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->atlasPay->onlyOrder();
        $gateway = $this->service(GatewayRegistry::class)->forPayment($payment);
        self::assertInstanceOf(AtlasPayGateway::class, $gateway);

        self::assertSame([OnlineState::Waiting, false], [$gateway->check($payment)->state, $gateway->check($payment)->moneyIn], 'awaiting the transfer: nothing in yet');
        foreach (['admin_review', 'underpaid_review', 'underpaid_awaiting_remainder'] as $state) {
            $this->atlasPay->review($id, $state);
            $verdict = $gateway->check($payment);
            self::assertSame([OnlineState::Waiting, true], [$verdict->state, $verdict->moneyIn], $state);
        }
    }

    public function testOneInReviewWaitsARejectionAfterItIsHeldForSupportAndAnExpiredOrderClosesItsPage(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $id = $this->atlasPay->onlyOrder();
        $online = $this->service(OnlinePayments::class);

        foreach (['admin_review', 'underpaid_review', 'underpaid_awaiting_remainder'] as $state) {
            $this->atlasPay->review($id, $state);
            self::assertSame(OnlineState::Waiting, $online->check($payment->refresh()), $state);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->atlasPay->reject($id);
        try {
            $online->check($payment);
            self::fail('held for support');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_CLOSED_WITH_MONEY], [$payment->refresh()->status, $payment->note], 'money was said to be in: support looks at once');
        self::assertSame(OrderStatus::Pending, $payment->order->status);

        $other = $this->topUp('40000', $this->atlasPayMethod('اطلس‌پی ۲'))->payment()->refresh();
        $this->atlasPay->expire((int) $other->invoice['order_id']);
        self::assertSame(OnlineState::Closed, $online->check($other), 'nothing came in on it: closed, for the customer to pay again');
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$other->refresh()->status, $other->note]);
    }

    public function testTheCardAtlasPayGivesIsShownWhileThePageTakesMoneyAndKeptEncrypted(): void
    {
        $this->atlasPay->directInfo = true;

        $result = $this->topUp('50000');

        $card = $result->payLink()->card;
        self::assertNotNull($card);
        self::assertSame([FakeAtlasPay::CARD, 'Ali Rezaei', 'Bank Melli'], [$card->card, $card->holder, $card->bank]);
        self::assertStringStartsWith('https://t.me/atlaspaybot/', $result->payLink()->url, 'the mini app still: a receipt is sent there when the text does not come');
        $payment = $result->payment()->refresh();
        self::assertStringNotContainsString(FakeAtlasPay::CARD, json_encode($payment->invoice, JSON_THROW_ON_ERROR), 'kept encrypted');

        $gateway = $this->service(GatewayRegistry::class)->forPayment($payment);
        self::assertInstanceOf(AtlasPayGateway::class, $gateway);
        Carbon::setTestNow(now()->addMinutes(21));
        self::assertNull($gateway->initiate($payment->refresh())->link?->card, 'its page closed: no card');
    }

    public function testAShopNotOnHttpsIsAskedAboutAndNeverCalled(): void
    {
        $this->config(['app.url' => 'http://localhost:8080']);

        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        self::assertArrayNotHasKey('webhookUrl', $this->atlasPay->orders[$this->atlasPay->onlyOrder()]['request'], 'AtlasPay calls only https');
    }

    public function testACustomerWithoutTelegramIsNoOnesAtAtlasPay(): void
    {
        $this->customer = $this->webCustomer();

        $this->topUp('50000');

        self::assertArrayNotHasKey('customerTelegramId', $this->atlasPay->orders[$this->atlasPay->onlyOrder()]['request']);
    }

    public function testAWrongKeyOrAtlasPayOutOfReachOpensNothingAndChangesNothing(): void
    {
        $logs = $this->logs();
        $this->method = $this->atlasPayMethod('اطلس‌پی', key: 'atl_live_wrong_key_00');

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000')->outcome);
        self::assertTrue($logs->hasWarningThatContains('AtlasPay would not open an order (HTTP 401): X-API-Key نامعتبر است — the API key is wrong'));

        // An amount it will not take: its every fault in the log.
        $this->atlasPay->most = 40000;
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->atlasPayMethod('اطلس‌پی ۳'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 400): baseAmountToman must not be greater than 40000 — AtlasPay would not take the request'));
        $this->atlasPay->most = null;

        $payment = $this->topUp('40000', $this->atlasPayMethod('اطلس‌پی ۲'))->payment()->refresh();
        $this->atlasPay->down();
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

    /**
     * A gateway whose AtlasPay says order 58 is confirmed and paid in full, and `$answer` besides.
     *
     * @param array<string, mixed> $answer
     */
    private function verified(array $answer): AtlasPayGateway
    {
        return new AtlasPayGateway(FakeAtlasPay::KEY, self::client(new Response(200, [], (string) json_encode(
            ['success' => true, 'id' => 58, 'trackingCode' => 'abcd1234', 'status' => 'confirmed', 'paid' => true, 'actualReceivedAmountToman' => 50007, 'requiresManualDelivery' => false] + $answer,
        ))), $this->service(Encrypter::class));
    }

    /** A 50,000 payment opened on AtlasPay as order 58, as its invoice keeps it. */
    private static function opened(): Payment
    {
        return (new Payment())->forceFill([
            'amount' => '50000.00',
            'link' => 'https://t.me/atlaspaybot/pay?startapp=order_58_abcd1234',
            'link_expires_at' => now()->addMinutes(20),
            'invoice' => ['order_id' => 58, 'ref' => 'amo-1-abc', 'tracking_code' => 'abcd1234', 'total' => 50007, 'fee' => 7, 'card' => null],
        ]);
    }

    private static function client(Response ...$answers): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($answers)), 'http_errors' => false]);
    }
}
