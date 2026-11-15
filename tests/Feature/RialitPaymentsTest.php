<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Urls;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Controllers\GatewayWebhookController;
use App\Modules\Payments\Drivers\Rialit\RialitDriver;
use App\Modules\Payments\Drivers\Rialit\RialitGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\HttpTestCase;
use Tests\Support\FakeRialit;
use Tests\Support\FakeTelegram;

/**
 * Rialit, an online gateway paid on its page: a checkout sends a payment in Toman for the shop's order id and the customer
 * (their Telegram id: one with no Telegram account is not offered it) and shows what the payer pays — the order's sum and
 * the fee the merchant passes on in Rialit's dashboard, never credited. Its webhook, to the shop's one address for it, is not signed: it only
 * names a payment, and the shop asks Rialit about it — only about an unpaid payment of a Rialit method, not held for
 * support, a few times a minute at most, in the payment's own shop with its own method's key —; a test, or a payment the
 * shop does not know, is heard and asks nothing; a body not of Rialit's shape is a 400. Paid settles it, for the shop's
 * own order and sum (held otherwise); pending waits while the page takes money and closes after; expired or cancelled
 * closes; a late approval still settles it.
 */
final class RialitPaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeRialit $rialit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->telegram();
        $this->rialit = $this->rialit();
        $this->customer = $this->customer();
        $this->method = $this->rialitMethod();
    }

    public function testItIsAnOnlineGatewayOfAnApiKeyWhoseWebhookAddressIsInItsNotes(): void
    {
        $driver = $this->service(RialitDriver::class);
        $described = $driver->describe()->toArray();
        self::assertSame(['rialit', 'ریالیت'], [$described['key'], $described['label']]);
        self::assertEquals((object) ['kind' => 'online', 'builtin' => false], $described['traits']);
        self::assertSame(['api_key' => 'secret'], array_column($described['fields'], 'type', 'name'));
        self::assertStringContainsString('https://shop.example/payments/webhooks/rialit ', $described['notes'][0], 'the address to paste in its Webhook tab');
        self::assertNull($driver->summary(['api_key' => FakeRialit::KEY]));
        self::assertNotNull($this->service(GatewayRegistry::class)->find(RialitGateway::key()));
    }

    public function testACheckoutSendsAPaymentAndShowsWhatThePayerPaysWithTheFee(): void
    {
        $this->rialit->feePercent = 12;

        $result = $this->topUp('100000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $token = $this->rialit->onlyPayment();
        self::assertSame(RialitGateway::PAGE . $token, $result->payLink()->url);
        self::assertSame(['113650.00', '13650.00'], [$result->payLink()->amount, $result->payLink()->fee], '12% grossed up and rounded to 50 Toman, apart');
        self::assertEqualsWithDelta(time() + 30 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);

        $payment = $result->payment()->refresh();
        $orderId = $this->service(OnlineInvoices::class)->orderId($payment);
        self::assertSame(['amount' => 100000, 'order_id' => $orderId, 'payer_id' => self::TELEGRAM_ID], $this->rialit->payments[$token]['request'], 'in Toman, exactly its three fields');
        self::assertSame('Bearer ' . FakeRialit::KEY, $this->rialit->calls[0]['authorization']);
        self::assertSame([$token, PaymentStatus::Pending, '100000.00'], [$payment->reference, $payment->status, $payment->amount]);
        self::assertSame(['token' => $token, 'order_id' => $orderId, 'amount' => 100000, 'payer_amount' => 113650, 'payer_fee' => 13650], $payment->invoice);
    }

    public function testNoFeeShowsTheOrdersOwnSum(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(['50000.00', null], [$result->payLink()->amount, $result->payLink()->fee]);
    }

    public function testItsWebhookOnlyMakesTheShopAskAndAPaidPaymentIsDeliveredOnce(): void
    {
        $this->rialit->feePercent = 12;
        $payment = $this->topUp('100000')->payment()->refresh();
        $token = $this->rialit->onlyPayment();

        // It says paid before anything was: Rialit is asked, and says it is pending.
        $answer = $this->webhook($this->rialit->webhook('payment.paid', $token));
        self::assertSame([200, ['received' => true]], [$answer->getStatusCode(), $this->decode($answer)]);
        self::assertCount(1, $this->rialit->statusCalls());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        $this->rialit->pay($token);
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $token))->getStatusCode());
        $payment->refresh();
        self::assertSame([PaymentStatus::Paid, $token, OrderStatus::Fulfilled], [$payment->status, $payment->reference, $payment->order->status]);
        self::assertSame('100000.00', $this->customer->balance(), 'the order\'s sum: the fee is Rialit\'s');

        // Sent again (Rialit retries until it hears a 200): heard, and nothing asked of a settled payment.
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $token))->getStatusCode());
        self::assertCount(2, $this->rialit->statusCalls());
        self::assertSame('100000.00', $this->customer->balance());
    }

    public function testItsTestAndEventsAboutNoPaymentOfTheShopsAreHeardAndAskNothing(): void
    {
        $this->topUp('50000');

        $test = $this->webhook($this->rialit->webhook('webhook.test'));
        self::assertSame([200, ['received' => true]], [$test->getStatusCode(), $this->decode($test)]);

        // A burst of forged tokens: heard, and Rialit never asked.
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', bin2hex(random_bytes(12))))->getStatusCode());
        }
        // An event of a type the shop does not know, a token not of Rialit's shape, no data at all.
        self::assertSame(200, $this->webhook($this->rialit->webhook('merchant.updated', $this->rialit->onlyPayment()))->getStatusCode());
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', null, ['data' => ['id' => 'not a token!']]))->getStatusCode());
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', null, ['data' => null]))->getStatusCode());

        self::assertSame([], $this->rialit->statusCalls());
        self::assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
    }

    public function testAWebhookNamingAPaymentOfAnotherGatewayAsksNothing(): void
    {
        $variza = $this->variza();
        $this->topUp('50000', $this->varizaMethod());
        $this->outgoingFake(FakeRialit::class);

        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $variza->onlyLink()))->getStatusCode());
        self::assertSame([], $this->rialit->statusCalls());
    }

    public function testAWebhookActsInItsPaymentsOwnShopWithItsOwnMethodsKey(): void
    {
        // The main shop's Rialit method holds a key Rialit does not know: an agent's payment is asked with its own method's.
        $this->method->delete();
        $this->rialitMethod(key: 'rlt_live_wrong_key_0000');
        $bot = $this->agentBot();
        // The same Telegram account a customer in both shops.
        [$customer, $method] = CurrentBot::run($bot, fn(): array => [$this->customer(), $this->rialitMethod('ریالیت نماینده')]);
        $payment = CurrentBot::run($bot, fn(): Payment => $this->topUp('50000', $method, $customer)->payment()->refresh());
        $token = (string) $payment->reference;
        $this->rialit->pay($token);
        $telegram = $this->telegram();
        $telegram->reset();

        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $token))->getStatusCode());

        self::assertSame(['Bearer ' . FakeRialit::KEY], array_column($this->rialit->statusCalls(), 'authorization'), 'asked once, with the agent\'s method\'s key');
        self::assertSame([PaymentStatus::Paid, '50000.00'], CurrentBot::run($bot, static fn(): array => [$payment->refresh()->status, $customer->balance()]), 'settled in its own shop');
        self::assertSame('0.00', $this->customer->balance(), 'the main shop\'s customer of the same Telegram account untouched');
        $told = array_keys(array_filter($telegram->history, static fn(array $call): bool => ($call['params']['chat_id'] ?? null) === (string) self::TELEGRAM_ID));
        self::assertNotSame([], $told, 'the customer is told');
        foreach ($told as $index) {
            self::assertSame(FakeTelegram::AGENT_TOKEN, $telegram->tokenOf($index), 'by the agent\'s bot');
        }
    }

    public function testABurstNamingARealPaymentAsksRialitAFewTimesAMinuteAtMost(): void
    {
        $token = $this->topUp('50000')->payment()->refresh()->reference;
        self::assertIsString($token);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $token))->getStatusCode());
        }

        self::assertCount(6, $this->rialit->statusCalls());
    }

    public function testABodyThatIsNotRialitsIsA400AndAnAddressWithNoMethodA404(): void
    {
        $logs = $this->logs();
        $token = $this->topUp('50000')->payment()->refresh()->reference;
        self::assertIsString($token);

        $answer = $this->webhook(['body' => 'payment paid', 'headers' => ['X-Rialit-Event' => 'payment.paid', 'X-Rialit-Delivery' => 'x']]);
        self::assertSame([400, GatewayWebhookController::FOREIGN], [$answer->getStatusCode(), $this->decode($answer)['message']]);
        self::assertTrue($logs->hasWarningThatContains('A rialit webhook came that is not the gateway\'s'));

        // Headers that do not say the body's event or delivery, or none at all.
        $webhook = $this->rialit->webhook('payment.paid', $token);
        self::assertSame(400, $this->webhook(['headers' => ['X-Rialit-Event' => 'payment.expired'] + $webhook['headers']] + $webhook)->getStatusCode());
        self::assertSame(400, $this->webhook(['headers' => ['X-Rialit-Delivery' => 'another-delivery'] + $webhook['headers']] + $webhook)->getStatusCode());
        self::assertSame(400, $this->webhook(['headers' => ['Content-Type' => 'application/json']] + $webhook)->getStatusCode());
        self::assertSame(400, $this->webhook(['body' => '[]'] + $webhook)->getStatusCode());
        self::assertSame([], $this->rialit->statusCalls());

        PaymentMethod::query()->where('driver', RialitGateway::key())->update(['enabled' => false]);
        self::assertSame(200, $this->webhook($webhook)->getStatusCode(), 'a method switched off still hears about its payments');
        Payment::query()->delete();
        PaymentMethod::query()->where('driver', RialitGateway::key())->delete();
        $none = $this->webhook($webhook);
        self::assertSame([404, GatewayWebhookController::UNKNOWN], [$none->getStatusCode(), $this->decode($none)['message']]);
    }

    public function testPendingWaitsWhileThePageTakesMoneyThenClosesAndALateApprovalStillSettles(): void
    {
        $online = $this->service(OnlinePayments::class);
        $payment = $this->topUp('50000')->payment()->refresh();

        self::assertSame(OnlineState::Waiting, $online->check($payment), 'the customer may still be paying');

        Carbon::setTestNow(now()->addMinutes(31));
        self::assertSame(OnlineState::Closed, $online->check($payment));
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$payment->refresh()->status, $payment->note]);

        // Rialit approved it late, after it had said expired: still the customer's money.
        $this->rialit->expire((string) $payment->reference);
        $this->rialit->pay((string) $payment->reference);
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $payment->reference))->getStatusCode());
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testExpiredOrCancelledClosesItsPage(): void
    {
        $online = $this->service(OnlinePayments::class);
        $expired = $this->topUp('50000')->payment()->refresh();
        $this->rialit->expire((string) $expired->reference);

        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.expired', $expired->reference))->getStatusCode());
        self::assertSame([PaymentStatus::Failed, PaymentService::NOTE_LINK_CLOSED], [$expired->refresh()->status, $expired->note], 'closed while its page was still open');

        $cancelled = $this->topUp('60000', $this->rialitMethod('ریالیت ۲'))->payment()->refresh();
        $this->rialit->cancel((string) $cancelled->reference);
        self::assertSame(OnlineState::Closed, $online->check($cancelled));
        self::assertSame(PaymentStatus::Failed, $cancelled->refresh()->status);
    }

    public function testAPaidAnswerForAnotherOrderOrSumIsHeldForSupport(): void
    {
        $online = $this->service(OnlinePayments::class);
        $payment = $this->topUp('50000')->payment()->refresh();
        $token = (string) $payment->reference;
        $this->rialit->pay($token);
        $this->rialit->payments[$token]['amount'] = 20000;

        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $token))->getStatusCode(), 'heard: Rialit need not send it again');
        $payment->refresh();
        self::assertSame([PaymentStatus::Pending, PaymentService::NOTE_HELD], [$payment->status, $payment->note]);
        self::assertTrue(OnlinePayments::awaitsSupport($payment));
        self::assertSame('0.00', $this->customer->balance());

        // Support's now: its webhook sent again asks Rialit nothing.
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $token))->getStatusCode());
        self::assertCount(1, $this->rialit->statusCalls());

        $other = $this->topUp('60000', $this->rialitMethod('ریالیت ۲'))->payment()->refresh();
        $this->rialit->pay((string) $other->reference);
        $this->rialit->payments[(string) $other->reference]['order_id'] = 'amo-1-another';
        try {
            $online->check($other);
            self::fail('another order is never this payment');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
            self::assertStringContainsString('was paid for order "amo-1-another"', $e->detail);
        }
        self::assertSame(PaymentStatus::Pending, $other->refresh()->status);
    }

    public function testAnswersUnderDataAreReadAlike(): void
    {
        $this->rialit->wrapped = true;
        $this->rialit->feePercent = 12;

        $result = $this->topUp('100000');
        self::assertSame(['113650.00', '13650.00'], [$result->payLink()->amount, $result->payLink()->fee]);

        $payment = $result->payment()->refresh();
        $this->rialit->pay((string) $payment->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
    }

    public function testAnAnswerThatDoesNotDescribeThePaymentIsRefusedAtOpening(): void
    {
        $answers = [
            'no token' => ['amount' => 50000, 'payer_amount' => 50000, 'payer_fee' => 0],
            'a token not of its shape' => ['token' => 'a-b', 'amount' => 50000, 'payer_amount' => 50000, 'payer_fee' => 0],
            'another amount' => ['token' => 'abc123', 'amount' => 40000, 'payer_amount' => 40000, 'payer_fee' => 0],
            'the payer paying less' => ['token' => 'abc123', 'amount' => 50000, 'payer_amount' => 45000, 'payer_fee' => 0],
            'a fee that does not add up' => ['token' => 'abc123', 'amount' => 50000, 'payer_amount' => 56850, 'payer_fee' => 5000],
            'no payer amount' => ['token' => 'abc123', 'amount' => 50000],
        ];
        foreach ($answers as $case => $answer) {
            try {
                self::gateway(new Response(201, [], (string) json_encode($answer)))->open(new Payment(), self::request());
                self::fail("{$case}: refused");
            } catch (GatewayUnavailableException $e) {
                self::assertStringContainsString('Rialit made a payment it did not describe (HTTP 201)', $e->detail, $case);
            }
        }

        $invoice = self::gateway(new Response(200, [], (string) json_encode(['token' => 'abc123', 'payer_amount' => '56850', 'payer_fee' => 6850])))->open(new Payment(), self::request());
        self::assertSame(['abc123', RialitGateway::PAGE . 'abc123', 56850], [$invoice->reference, $invoice->url, $invoice->details['payer_amount']], 'no amount echoed, and digits for a number: taken');

        $invoice = self::gateway(new Response(201, [], (string) json_encode(['token' => 'abc123', 'amount' => 50000.0, 'payer_amount' => 56850.0, 'payer_fee' => 6850.0])))->open(new Payment(), self::request());
        self::assertSame([50000, 56850, 6850], [$invoice->details['amount'], $invoice->details['payer_amount'], $invoice->details['payer_fee']], 'whole numbers written with a fraction: taken');
    }

    public function testAStatusInCapitalsAndAnAmountWithAFractionAreReadAlike(): void
    {
        $payment = (new Payment())->forceFill(['invoice' => ['token' => 'abc123', 'order_id' => 'amo-1-abc', 'amount' => 50000]]);

        $verdict = self::gateway(new Response(200, [], (string) json_encode(['data' => ['status' => ' PAID ', 'order_id' => 'amo-1-abc', 'amount' => 50000.0]])))->check($payment);

        self::assertSame([OnlineState::Paid, 'abc123'], [$verdict->state, $verdict->reference]);
    }

    public function testItsRefusalsSayWhatTheyMeanForTheMerchant(): void
    {
        $logs = $this->logs();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->rialitMethod('ریالیت', key: 'rlt_live_wrong_key_0000'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('Rialit would not make a payment (HTTP 401): Invalid or revoked API key — the API key is wrong or was revoked'));

        $this->rialit->ipAllowed = false;
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->rialitMethod('ریالیت ۲'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 403): IP not allowed — the shop server\'s outgoing IP is not on the allowed IPs in Rialit\'s dashboard'));
        $this->rialit->ipAllowed = true;

        $this->rialit->active = false;
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->rialitMethod('ریالیت ۳'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 403): Merchant is not active — the merchant account at Rialit is not active'));
        $this->rialit->active = true;

        $this->rialit->limited = true;
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000', $this->rialitMethod('ریالیت ۴'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 429): Too many requests — too many requests just now'));
        $this->rialit->limited = false;

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('40000', $this->rialitMethod('ریالیت ۵'))->outcome);
        self::assertTrue($logs->hasWarningThatContains('(HTTP 400): Amount out of range: amount must be between 50000 and 10000000 — Rialit would not take the request'));

        // Its order id again, with another sum.
        $payment = $this->topUp('50000', $this->rialitMethod('ریالیت ۶'))->payment()->refresh();
        $gateway = $this->service(GatewayRegistry::class)->forPayment($payment);
        self::assertInstanceOf(RialitGateway::class, $gateway);
        try {
            $gateway->open($payment, new OnlineRequest($this->service(OnlineInvoices::class)->orderId($payment), 60000, 'https://shop.example/payments/callback/1/s', 'سفارش', (string) self::TELEGRAM_ID));
            self::fail('another sum on its order id');
        } catch (GatewayUnavailableException $e) {
            self::assertStringContainsString('(HTTP 409): order_id already used with different data — this order id was sent to Rialit before with another sum', $e->detail);
        }

        // A state the shop does not know, and Rialit in trouble: asked again later, nothing changed.
        $this->rialit->payments[(string) $payment->reference]['status'] = 'refunded';
        $this->assertUnanswered($payment, 'said payment ' . $payment->reference . ' is in a state the shop does not know (HTTP 200)');
        unset($this->rialit->payments[(string) $payment->reference]);
        $this->assertUnanswered($payment, '(HTTP 404): Payment not found — Rialit knows no such payment: was the method\'s key changed');
        $this->rialit->failing = 502;
        $this->assertUnanswered($payment, '(HTTP 502): Internal error — Rialit is in trouble just now');
    }

    public function testACustomerWithoutTelegramIsNotOfferedItAndRialitIsNeverAsked(): void
    {
        $methods = $this->service(PaymentMethods::class);
        $offered = static fn(array $list): array => array_map(static fn(PaymentMethod $method): int => $method->id, $list);
        self::assertContains($this->method->id, $offered($methods->forOrder(OrderType::WalletTopUp, '50000.00', $this->customer)), 'a customer with a Telegram account');
        $web = $this->webCustomer();

        self::assertNotContains($this->method->id, $offered($methods->forOrder(OrderType::WalletTopUp, '50000.00', $web)), 'Rialit\'s payer is a Telegram account: no other id stands in');
        self::assertNotContains($this->method->id, $offered($methods->forOrder(OrderType::WalletTopUp, null, $web)), 'whatever the amount');
        self::assertNull($methods->payable($this->method->id, OrderType::WalletTopUp, null, $web));
        try {
            $this->topUp('50000', customer: $web);
            self::fail('Not offered, not paid with');
        } catch (MethodUnavailableException $e) {
            self::assertStringContainsString('فقط با حساب تلگرام پرداخت می‌شود', $e->getMessage());
        }
        self::assertSame([0, []], [Order::query()->where('user_id', $web->id)->count(), $this->rialit->calls], 'nothing ordered, Rialit never asked');

        // Opened all the same (a customer whose Telegram account went meanwhile), the gateway itself refuses it unasked.
        $this->expectException(GatewayUnavailableException::class);
        self::gateway(new Response(200))->open(new Payment(), new OnlineRequest('amo-1-abc', 50000, 'https://shop.example/payments/callback/1/s', 'سفارش #1'));
    }

    public function testRialitOutOfReachOpensNothingAndChangesNothing(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $this->rialit->down();

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('60000', $this->rialitMethod('ریالیت ۲'))->outcome);
        $this->assertUnanswered($payment, 'Rialit is out of reach');
        self::assertSame(200, $this->webhook($this->rialit->webhook('payment.paid', $payment->reference))->getStatusCode(), 'heard: the shop asks on by itself');
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function testItsKeyIsKeptEncryptedAndNeverShown(): void
    {
        $this->loginAsAdmin();
        $add = fn(array $form) => $this->postJson('/api/admin/payment-methods', $form + ['driver' => 'rialit', 'label' => 'ریالیت', 'clear_api_key' => false]);

        self::assertSame(['api_key' => ['کلید API ریالیت را وارد کنید.']], $this->decode($add([]))['errors']);
        self::assertSame(['api_key'], array_keys($this->decode($add(['api_key' => 'has space']))['errors']));

        $response = $add(['api_key' => FakeRialit::KEY]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['method'];
        self::assertSame(['rialit', 'online', null], [$row['driver'], $row['kind'], $row['summary']]);
        self::assertSame(['api_key' => ['set' => true, 'hint' => '••••••6e3a']], $row['config']);
        $method = PaymentMethod::query()->findOrFail($row['id']);
        self::assertStringNotContainsString(FakeRialit::KEY, json_encode($method->config, JSON_THROW_ON_ERROR), 'kept encrypted');
    }

    private function topUp(string $amount, ?PaymentMethod $method = null, ?User $customer = null): CheckoutResult
    {
        $orders = $this->service(OrderService::class);
        $customer ??= $this->customer;

        return $this->service(Checkout::class)->pay($customer, $method ?? $this->method, OrderType::WalletTopUp, $amount, static fn(?OrderKey $key): Order => $orders->openTopUp($customer, (int) $amount, $key));
    }

    /** Asking about `$payment` cannot be answered, its detail saying `$detail`: still pending, not held. */
    private function assertUnanswered(Payment $payment, string $detail): void
    {
        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('Rialit could not say');
        } catch (GatewayUnavailableException $e) {
            self::assertFalse($e->held);
            self::assertStringContainsString($detail, $e->detail);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertFalse(OnlinePayments::isHeld($payment));
    }

    /**
     * Rialit's webhook as it posts it, to the shop's address for its driver.
     *
     * @param array{body: string, headers: array<string, string>} $webhook
     */
    private function webhook(array $webhook): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . str_replace('{driver:[a-z0-9]+}', RialitGateway::key(), Urls::PAYMENT_WEBHOOK))
            ->withBody((new StreamFactory())->createStream($webhook['body']));
        foreach ($webhook['headers'] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app()->http()->handle($request);
    }

    /** A gateway whose Rialit answers `$answer`. */
    private static function gateway(Response $answer): RialitGateway
    {
        return new RialitGateway(FakeRialit::KEY, new Client(['handler' => HandlerStack::create(new MockHandler([$answer])), 'http_errors' => false]));
    }

    /** A 50,000 Toman payment's request, for a customer with a Telegram account. */
    private static function request(): OnlineRequest
    {
        return new OnlineRequest('amo-1-abc', 50000, 'https://shop.example/payments/callback/1/s', 'سفارش #1', (string) self::TELEGRAM_ID);
    }
}
