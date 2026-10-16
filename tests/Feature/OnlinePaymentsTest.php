<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Scheduler;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Controllers\CallbackController;
use App\Modules\Payments\Drivers\CubePay\CubePayGateway;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\Enums\CheckoutOutcome;
use App\Modules\Payments\Enums\OnlineSettlement;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\MethodSettings;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\OnlineInvoices;
use App\Modules\Payments\Services\OnlinePayments;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Tasks\OnlinePaymentsTask;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Carbon;
use Psr\Http\Message\RequestInterface;
use Tests\HttpTestCase;
use Tests\Support\FakeCubePay;

/**
 * An online gateway, through CubePay: a checkout opens the payment on the gateway once and shows its page; what came of
 * it is only ever the gateway's word — asked when it calls back, when the customer or support asks, and now and then by
 * the task —, settled once and told once; money taken after its order closed goes to the wallet; a page whose time is
 * over gives way to a new payment, and is given up on long after — unless the gateway holds money on it, then support's
 * in the end; an answer that does not fit holds it for support, told once, its order paid no other way; the callback
 * asks a few times a minute at most; the return page says what came of it, and where to look.
 */
final class OnlinePaymentsTest extends HttpTestCase
{
    private User $customer;
    private PaymentMethod $method;
    private FakeCubePay $cubePay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['app.url' => 'https://shop.example']);
        $this->telegram();
        $this->cubePay = $this->cubePay();
        $this->customer = $this->customer(['username' => 'ali_1']);
        $this->method = $this->cubePayMethod();
    }

    public function testTheTaskAsksEveryMinuteInEveryShop(): void
    {
        self::assertSame(
            ['task' => OnlinePaymentsTask::class, 'interval' => 60, 'eachBot' => true],
            $this->service(Scheduler::class)->tasks()[OnlinePaymentsTask::class],
        );
    }

    public function testACheckoutOpensThePaymentOnCubePayOnceAndShowsItsPage(): void
    {
        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Link, $result->outcome);
        $payment = $result->payment()->refresh();
        $order = $this->cubePay->orders[$this->cubePay->onlyOrder()];
        self::assertSame('https://cubevps.ir/smspay/pay.php?authority=' . $order['authority'], $result->payLink()->url);
        self::assertSame('50037.00', $result->payLink()->amount, 'the exact sum CubePay asks: a few Toman more than the order');
        self::assertNull($result->payLink()->card, 'no card in the bot unless the merchant asked CubePay for it');
        self::assertEqualsWithDelta(time() + 30 * 60, $result->payLink()->expiresAt?->getTimestamp(), 5);

        self::assertSame([
            'order_id' => $this->service(OnlineInvoices::class)->orderId($payment),
            'price_amount' => 50000,
            'callback_url' => $this->service(OnlineInvoices::class)->callbackUrl($payment),
            'redirect_after_payment' => false,
            'description' => sprintf('سفارش #%d', $payment->order_id),
            'customer_user_id' => (string) self::TELEGRAM_ID,
            'customer_username' => 'ali_1',
        ], $order['request']);
        self::assertStringStartsWith("https://shop.example/payments/callback/{$payment->id}/", $order['request']['callback_url']);
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame('50000.00', $payment->amount, 'the payment is the order\'s amount: the Toman CubePay adds (and any fee share passed on) are CubePay\'s, never credited');
        self::assertSame($order['authority'], $payment->reference);

        $again = $this->topUp('50000');
        self::assertSame($payment->id, $again->payment()->id, 'the open order is paid on the same payment');
        self::assertSame($result->payLink()->url, $again->payLink()->url);
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE), 'opened on CubePay once');
    }

    public function testACardTheMerchantAskedToShowInTheBotComesWithThePage(): void
    {
        $this->cubePay->showCard = true;

        $card = $this->topUp('50000')->payLink()->card;

        self::assertNotNull($card);
        self::assertSame([FakeCubePay::CARD, 'CubePay Holder'], [$card->card, $card->holder]);
    }

    public function testACallbackOnlyMakesTheShopAskAndThePaymentSettlesOnceTold(): void
    {
        $payment = $this->topUp('50000')->payment();
        $callback = $this->callbackPath($payment);

        // A call that says it was paid, before anything was: CubePay is asked, and says it is waiting.
        $answer = $this->send('POST', $callback, ['success' => true, 'status' => 'paid', 'authority' => $payment->reference]);
        self::assertSame(['paid' => false], $this->decode($answer));
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertNotNull($payment->checked_at);

        // Another payment's secret, or none: nobody is asked.
        $asked = count($this->cubePay->calls);
        self::assertSame(404, $this->send('POST', "/payments/callback/{$payment->id}/" . str_repeat('0', 40))->getStatusCode());
        self::assertSame(404, $this->send('GET', '/payments/callback/999999/' . str_repeat('0', 40))->getStatusCode());
        self::assertCount($asked, $this->cubePay->calls);

        $this->cubePay->pay((string) $payment->reference);
        self::assertSame(['paid' => true], $this->decode($this->send('GET', $callback . '?authority=' . $payment->reference . '&status=paid')));
        $payment->refresh();
        self::assertSame(PaymentStatus::Paid, $payment->status);
        self::assertNull($payment->reviewer, 'nobody approved it by hand');
        self::assertSame(OrderStatus::Fulfilled, $payment->order->status);
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count());

        // CubePay calls again (or the customer asks): "verified once already" — paid, nothing twice.
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback, [])));
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment->refresh()));
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count(), 'told once');
    }

    public function testAPaidPageCubePayVerifiedBeforeIsPaidAllTheSame(): void
    {
        $payment = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $payment->reference);
        // A first verification whose answer never reached the shop: CubePay says 409 from then on.
        $this->cubePay->orders[$this->cubePay->onlyOrder()]['verified'] = true;

        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testACryptoPaymentOrAChoiceIsAskedByItsOrderId(): void
    {
        foreach (['crypto' => 'https://cubevps.ir/crypto/pay.php', 'choice' => 'https://cubevps.ir/pay/choose.php'] as $kind => $page) {
            $this->cubePay->kind = $kind;
            $result = $this->topUp('20000');
            $payment = $result->payment()->refresh();
            self::assertStringStartsWith($page, $result->payLink()->url);
            self::assertSame('20000.00', $result->payLink()->amount, 'no sum of its own: the order\'s');
            self::assertNull($payment->reference, 'no authority until the customer pays');

            self::assertSame(OnlineState::Waiting, $this->service(OnlinePayments::class)->check($payment));
            $this->cubePay->pay($this->service(OnlineInvoices::class)->orderId($payment));
            self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
            self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, $kind);
        }
        self::assertSame('40000.00', $this->customer->balance());
        self::assertCount(0, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'no authority to verify');
    }

    public function testAPageWhoseTimeRanOutIsClosedAndANewPaymentOpened(): void
    {
        $first = $this->topUp('50000')->payment();

        // Two minutes left on its page: too little to pay on, so the checkout opens another.
        Carbon::setTestNow(now()->addMinutes(28));
        $second = $this->topUp('50000')->payment();
        self::assertNotSame($first->id, $second->id);
        self::assertCount(2, $this->cubePay->callsTo(CubePayGateway::CREATE));
        self::assertSame(PaymentStatus::Pending, $first->refresh()->status, 'left for CubePay to say what came of it');

        $this->cubePay->close((string) $first->reference);
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($first));
        self::assertSame(PaymentStatus::Failed, $first->refresh()->status);
        self::assertSame(PaymentService::NOTE_LINK_CLOSED, $first->note);
        self::assertSame(OrderStatus::Pending, $first->order->status, 'the order is still the customer\'s to pay');

        // Paid after all — CubePay takes a late payment its merchant approves —: settled as it is.
        $this->cubePay->pay((string) $first->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($first));
        self::assertSame(PaymentStatus::Paid, $first->refresh()->status);
        self::assertSame(PaymentStatus::Cancelled, $second->refresh()->status, 'the order\'s other payment goes');
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAPagePaidJustBeforeItClosesIsFoundBeforeANewOneOpens(): void
    {
        $first = $this->topUp('50000')->payment();
        // The customer paid; the bank's text has not reached CubePay's ears yet when they tap the method again.
        $this->cubePay->pay((string) $first->reference);
        Carbon::setTestNow(now()->addMinutes(28));

        $again = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Settled, $again->outcome, 'the order as it stands: paid on its page');
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE), 'no second page to pay twice on');
        self::assertSame(PaymentStatus::Paid, $first->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(0, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count(), 'the checkout\'s answer is the word on it, once');
    }

    public function testAFeePassedOnIsAskedOfCubePayAndNeverCredited(): void
    {
        $driver = $this->service(GatewayRegistry::class)->find(CubePayGateway::key()) ?? self::fail('CubePay is registered');
        $this->method->forceFill(['config' => $this->service(MethodSettings::class)->write($driver, ['token' => FakeCubePay::TOKEN, 'fee_percent' => '3', 'fee_fixed' => 500])])->save();

        $result = $this->topUp('50001');

        // 3% of 50,001 is 1,500.03 — rounded up to 1,501 — and 500 more.
        self::assertSame(52002, $this->cubePay->orders[$this->cubePay->onlyOrder()]['request']['price_amount']);
        self::assertSame(['52039.00', '2001.00'], [$result->payLink()->amount, $result->payLink()->fee], 'the sum asked, CubePay\'s few Toman too, and of it the fee');
        $payment = $result->payment()->refresh();
        self::assertSame('50001.00', $payment->amount, 'the payment is the order\'s');

        $this->cubePay->pay((string) $payment->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame('50001.00', $this->customer->balance(), 'the top-up credits its amount, never the fee');
    }

    public function testMoneyTakenAfterItsOrderClosedGoesToTheWallet(): void
    {
        $this->reportGroup();
        $payment = $this->topUp('50000')->payment();
        $this->service(PaymentService::class)->cancelWithOrder($payment, 'admin', null);
        self::assertSame(OrderStatus::Cancelled, $payment->order->refresh()->status);

        $this->cubePay->pay((string) $payment->reference);
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment->refresh()));

        $payment->refresh();
        self::assertSame(PaymentStatus::Paid, $payment->status);
        self::assertSame(PaymentService::NOTE_TO_WALLET, $payment->note);
        self::assertSame(OrderStatus::Cancelled, $payment->order->status, 'the order stays closed');
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(WalletService::describeLatePayment($payment), WalletTransaction::query()->where('user_id', $this->customer->id)->latest('id')->value('description'));
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentCredited->value)->count());
        self::assertSame(0, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count());
        self::assertStringContainsString('پرداخت آنلاین پس از بسته شدن سفارش', (string) ReportMessage::query()->latest('id')->value('text'));

        // Asked again: paid already, nothing twice.
        self::assertSame(OnlineState::Paid, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAGatewayThatWillNotOpenItFailsThePaymentAndLeavesTheOrderOpen(): void
    {
        $logs = $this->logs();
        $this->cubePay->refuse(402, 'موجودی کیف‌پول کارمزد کافی نیست.');

        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Refused, $result->outcome);
        self::assertSame(PaymentStatus::Failed, $result->payment()->status);
        self::assertSame(GatewayUnavailableException::UNAVAILABLE, $result->payment()->note);
        self::assertSame(OrderStatus::Pending, $result->order()->status);
        self::assertTrue($logs->hasWarningThatContains('the fee wallet at CubePay is short'));

        // Paid on another try, the same order on a payment of its own.
        $again = $this->topUp('50000');
        self::assertSame(CheckoutOutcome::Link, $again->outcome);
        self::assertNotSame($result->payment()->id, $again->payment()->id);
    }

    public function testAShopNotOnHttpsCannotBeCalledBackAndOpensNothing(): void
    {
        $logs = $this->logs();
        $this->config(['app.url' => 'http://shop.example']);

        $result = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Refused, $result->outcome);
        self::assertSame([], $this->cubePay->calls);
        self::assertTrue($logs->hasWarningThatContains('APP_URL must start with https://'));
    }

    public function testAWrongTokenIsTheOwnersToFix(): void
    {
        $logs = $this->logs();
        $this->method = $this->cubePayMethod('CubePay', 'cp-wrong-token');

        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000')->outcome);
        self::assertTrue($logs->hasWarningThatContains('the API token is wrong'));
    }

    public function testCubePayOutOfReachChangesNothing(): void
    {
        $payment = $this->topUp('50000')->payment();
        $this->cubePay->down();

        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('a gateway out of reach says nothing');
        } catch (GatewayUnavailableException $e) {
            self::assertStringContainsString('out of reach', $e->detail);
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertSame(503, $this->send('POST', $this->callbackPath($payment))->getStatusCode(), 'CubePay calls again');
    }

    public function testTheTaskAsksAboutUnpaidPaymentsEveryFewMinutesAndGivesUpLongAfterTheirPageClosed(): void
    {
        $payment = $this->topUp('50000')->payment();
        $task = $this->service(OnlinePaymentsTask::class);

        $task->run();
        $verifies = count($this->cubePay->callsTo(CubePayGateway::VERIFY));
        self::assertSame(1, $verifies);
        $task->run();
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'not asked again within its minutes');

        Carbon::setTestNow(now()->addMinutes(OnlinePaymentsTask::CHECK_MINUTES));
        $this->cubePay->pay((string) $payment->reference);
        $task->run();
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());

        // One CubePay never answers about: closed once its page has been over for a while.
        $quiet = $this->topUp('30000')->payment();
        $this->cubePay->down();
        Carbon::setTestNow(now()->addMinutes(29));
        $task->run();
        self::assertSame(PaymentStatus::Pending, $quiet->refresh()->status, 'within its page\'s time and the late window');
        Carbon::setTestNow(now()->addHours(OnlinePaymentsTask::LATE_HOURS)->addMinutes(2));
        $task->run();
        self::assertSame(PaymentStatus::Failed, $quiet->refresh()->status);
    }

    public function testALatePaymentOnAClosedPageIsStillFoundByTheTask(): void
    {
        $payment = $this->topUp('50000')->payment();
        $task = $this->service(OnlinePaymentsTask::class);
        $this->cubePay->close((string) $payment->reference);

        Carbon::setTestNow(now()->addMinutes(31));
        $task->run();
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status, 'its page closed unpaid');

        // The merchant approves a late receipt at CubePay, and its callback is lost: the task finds it, less often now.
        $this->cubePay->pay((string) $payment->reference);
        Carbon::setTestNow(now()->addMinutes(OnlinePaymentsTask::LATE_CHECK_MINUTES - 1));
        $task->run();
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status, 'not asked again so soon');
        Carbon::setTestNow(now()->addMinutes(2));
        $task->run();
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        self::assertSame('50000.00', $this->customer->balance());

        // Past its late hours, a closed one is asked no more.
        $quiet = $this->topUp('30000')->payment();
        $this->cubePay->close((string) $quiet->reference);
        Carbon::setTestNow(now()->addMinutes(31));
        $task->run();
        Carbon::setTestNow(now()->addHours(OnlinePaymentsTask::LATE_HOURS));
        $asked = count($this->cubePay->calls);
        $task->run();
        self::assertCount($asked, $this->cubePay->calls);
    }

    public function testMoneyPaidOnTheOpenPageOfACancelledOrderIsFoundByTheTask(): void
    {
        $payment = $this->topUp('50000')->payment();
        $this->service(PaymentService::class)->cancelWithOrder($payment, 'admin', null);
        $this->cubePay->pay((string) $payment->reference);

        $this->service(OnlinePaymentsTask::class)->run();

        self::assertTrue($payment->refresh()->wentToWallet());
        self::assertSame('50000.00', $this->customer->balance());
    }

    public function testAnAnswerThatDoesNotFitThePaymentHoldsItForSupport(): void
    {
        $logs = $this->logs();
        $this->reportGroup();
        $payment = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $payment->reference);
        // CubePay verifies the authority for less than the order: its next word would be "verified before" (409).
        $this->cubePay->orders[$this->cubePay->onlyOrder()]['price'] = 40000;

        foreach ([1, 2] as $ask) {
            try {
                $this->service(OnlinePayments::class)->check($payment->refresh());
                self::fail('a verification that does not fit is never a payment');
            } catch (GatewayUnavailableException $e) {
                self::assertTrue($e->held, "ask {$ask}");
            }
        }
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'held: CubePay is not asked again');
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame(PaymentService::NOTE_HELD, $payment->note);
        self::assertTrue(OnlinePayments::isHeld($payment));
        self::assertFalse($this->service(PaymentActions::class)->allowed($payment)['check'], 'support checks it at CubePay: asking would only say "verified before"');
        self::assertTrue($logs->hasErrorThatContains('is held for support'));
        self::assertSame('0.00', $this->customer->balance());

        // Told once to the report group's «خطاها»: who, for what, its id at CubePay, and why.
        $reports = ReportMessage::query()->where('topic', Topic::Errors->value)->pluck('text')->all();
        self::assertCount(1, $reports);
        self::assertStringContainsString(sprintf('پرداخت آنلاین برای بررسی پشتیبانی نگه داشته شد</b> · پرداخت #%d', $payment->id), $reports[0]);
        self::assertStringContainsString(sprintf('شارژ کیف پول · سفارش #%d', $payment->order_id), $reports[0]);
        self::assertStringContainsString('<code>' . $payment->reference . '</code>', $reports[0]);
        self::assertStringContainsString(PaymentService::NOTE_HELD, $reports[0]);
        self::assertStringContainsString('for another order or amount', $reports[0]);
        self::assertStringNotContainsString(FakeCubePay::TOKEN, $reports[0]);

        // Left out of the task: never asked again, never closed, and no word of it in the log run after run.
        foreach ([1, 2, 3] as $run) {
            Carbon::setTestNow(now()->addHours(OnlinePaymentsTask::LATE_HOURS + 1));
            $this->service(OnlinePaymentsTask::class)->run();
        }
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY));
        self::assertFalse($logs->hasWarningThatContains('could not be asked about'));

        // Its order never expires by itself, and is paid no other way meanwhile: the customer would pay twice.
        self::assertFalse($this->service(PaymentService::class)->expire($payment->order, now()->addMinute()));
        foreach ([$this->method, $this->cardMethod()] as $method) {
            try {
                $this->topUp('50000', $method);
                self::fail('an order whose money is with support is paid no other way');
            } catch (PaymentHeldException $e) {
                self::assertSame(sprintf(PaymentHeldException::MESSAGE, $payment->order_id), $e->getMessage());
            }
        }
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE), 'no page opened again');

        // Its gateway calling back is answered so it stops; a browser is told to wait for support.
        $server = $this->send('POST', $this->callbackPath($payment));
        self::assertSame([200, ['paid' => false]], [$server->getStatusCode(), $this->decode($server)]);
        $page = $this->send('GET', $this->callbackPath($payment), null, ['Accept' => 'text/html']);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString(sprintf(PaymentHeldException::MESSAGE, $payment->order_id), (string) $page->getBody());
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY));
    }

    public function testAnAskThatWaitedItsTurnNeverSettlesWhatTheAskBeforeItHeld(): void
    {
        $payment = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $payment->reference);
        $this->cubePay->orders[$this->cubePay->onlyOrder()]['price'] = 40000;
        // The customer's check read the payment before the callback's ask held it, then waited its turn behind that ask.
        $customers = Payment::query()->with('method')->findOrFail($payment->id);

        foreach ([$payment, $customers] as $ask) {
            try {
                $this->service(OnlinePayments::class)->check($ask);
                self::fail('a verification that does not fit is never a payment');
            } catch (GatewayUnavailableException $e) {
                self::assertTrue($e->held);
            }
        }

        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'never asked again: its "verified before" is no payment');
        self::assertSame([PaymentStatus::Pending, '0.00'], [$payment->refresh()->status, $this->customer->balance()]);
    }

    public function testASettlementNeverTakesAPaymentHeldMeanwhile(): void
    {
        $payment = $this->topUp('50000')->payment()->refresh();
        $payment->forceFill(['invoice' => ['held' => 'test'] + ($payment->invoice ?? []), 'note' => PaymentService::NOTE_HELD])->save();

        self::assertSame(OnlineSettlement::Decided, $this->service(PaymentService::class)->settleOnline($payment, null));
        self::assertSame([PaymentStatus::Pending, OrderStatus::Pending, '0.00'], [$payment->refresh()->status, $payment->order->status, $this->customer->balance()]);
    }

    public function testAHoldTheCheckoutsOwnAskCausesStopsTheCheckout(): void
    {
        $first = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $first->reference);
        $this->cubePay->orders[$this->cubePay->onlyOrder()]['price'] = 40000;
        // Its page about to close: asked before another opens — and its answer holds it.
        Carbon::setTestNow(now()->addMinutes(28));

        try {
            $this->topUp('50000');
            self::fail('an order whose money just went to support is paid no other way');
        } catch (PaymentHeldException $e) {
            self::assertSame(sprintf(PaymentHeldException::MESSAGE, $first->order_id), $e->getMessage());
        }
        self::assertTrue(OnlinePayments::awaitsSupport($first->refresh()));
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE), 'no second page');
    }

    public function testAFailedPaymentALateAnswerHeldKeepsItsOrderWaitingForSupport(): void
    {
        $payment = $this->topUp('50000')->payment();
        $this->cubePay->close((string) $payment->reference);
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment));
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status);

        // CubePay verifies it after all — for less: held while failed.
        $this->cubePay->pay((string) $payment->reference);
        $this->cubePay->orders[$this->cubePay->onlyOrder()]['price'] = 40000;
        try {
            $this->service(OnlinePayments::class)->check($payment);
            self::fail('held');
        } catch (GatewayUnavailableException $e) {
            self::assertTrue($e->held);
        }

        self::assertTrue(OnlinePayments::awaitsSupport($payment->refresh()));
        self::assertTrue($payment->isWithSupport());
        self::assertFalse($this->service(PaymentService::class)->expire($payment->order, now()->addMinute()), 'its order waits for support');
        $this->expectException(PaymentHeldException::class);
        $this->topUp('50000');
    }

    public function testAHeldPaymentSupportCancelledHoldsNothing(): void
    {
        $payment = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $payment->reference);
        $this->cubePay->orders[$this->cubePay->onlyOrder()]['price'] = 40000;
        try {
            $this->service(OnlinePayments::class)->check($payment);
        } catch (GatewayUnavailableException) {
            // Held.
        }

        // Support settles it with the customer at CubePay, then cancels it here.
        $this->service(PaymentService::class)->cancel($payment->refresh(), 'admin', 'با مشتری در درگاه حل شد');

        self::assertFalse(OnlinePayments::awaitsSupport($payment->refresh()));
        self::assertSame(OnlineState::Closed, $this->service(OnlinePayments::class)->check($payment), 'nothing left to ask: answered closed, not held');
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY));
        self::assertNotInstanceOf(PaymentHeldException::class, $this->checkoutRefusal());
    }

    public function testHeldPaymentsNeverCrowdOutTheTasksOtherPayments(): void
    {
        $logs = $this->logs();
        // More held ones than a run asks about, long past their late hours.
        foreach (range(1, 10) as $i) {
            $held = $this->topUp((string) (60000 + $i * 1000))->payment()->refresh();
            $held->forceFill(['invoice' => ['held' => 'test'] + ($held->invoice ?? []), 'note' => PaymentService::NOTE_HELD])->save();
        }
        Carbon::setTestNow(now()->addHours(3));
        $fresh = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $fresh->reference);

        $this->service(OnlinePaymentsTask::class)->run();

        self::assertSame(PaymentStatus::Paid, $fresh->refresh()->status, 'its callback lost, the task found it');
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'none of the held ones asked');
        self::assertFalse($logs->hasWarningThatContains('could not be asked about'));
    }

    public function testMoneyInAtTheGatewayIsAskedOnUntilItsReviewHoursThenHeldForSupport(): void
    {
        $this->reportGroup();
        $payment = $this->topUp('50000')->payment()->refresh();
        // What a gateway's "money in, still under way" leaves (OnlineVerdict::waiting(moneyIn: true)): a mark on the payment.
        $payment->forceFill(['invoice' => ['money_in' => true] + $payment->invoice])->save();
        $closed = $payment->link_expires_at->copy();
        $task = $this->service(OnlinePaymentsTask::class);
        $verifies = fn(): int => count($this->cubePay->callsTo(CubePayGateway::VERIFY));

        // Past its late hours: never closed, asked as often as a closed page is.
        Carbon::setTestNow($closed->copy()->addHours(OnlinePaymentsTask::LATE_HOURS + 1));
        $task->run();
        $asked = $verifies();
        Carbon::setTestNow(now()->addMinutes(OnlinePaymentsTask::LATE_CHECK_MINUTES - 1));
        $task->run();
        self::assertSame($asked, $verifies(), 'not asked again so soon');
        Carbon::setTestNow(now()->addMinutes(2));
        $task->run();
        self::assertSame($asked + 1, $verifies());
        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);

        // Its order expires as any idle one does; the payment, cancelled with it, is asked on all the same.
        Carbon::setTestNow($payment->updated_at->copy()->addHours(ExpireOrdersTask::EXPIRE_HOURS)->addMinute());
        $this->service(ExpireOrdersTask::class)->run();
        self::assertSame([PaymentStatus::Cancelled, OrderStatus::Cancelled], [$payment->refresh()->status, $payment->order->status]);
        $asked = $verifies();
        $task->run();
        self::assertSame($asked + 1, $verifies());
        self::assertFalse(OnlinePayments::isHeld($payment->refresh()));

        // REVIEW_HOURS after its page closed: support's, told in «خطاها», and asked no more.
        Carbon::setTestNow($closed->copy()->addHours(OnlinePaymentsTask::REVIEW_HOURS)->addMinute());
        $task->run();
        $payment->refresh();
        self::assertTrue(OnlinePayments::isHeld($payment));
        self::assertSame(PaymentService::NOTE_UNSETTLED, $payment->note);
        self::assertStringContainsString(PaymentService::NOTE_UNSETTLED, (string) ReportMessage::query()->where('topic', Topic::Errors->value)->value('text'));
        $asked = $verifies();
        Carbon::setTestNow(now()->addHour());
        $task->run();
        self::assertSame($asked, $verifies());
    }

    public function testTheCallbackAsksTheGatewayAFewTimesAMinuteAtMost(): void
    {
        $payment = $this->topUp('50000')->payment();
        $callback = $this->callbackPath($payment);

        foreach (range(1, 25) as $call) {
            self::assertSame(['paid' => false], $this->decode($this->send('POST', $callback)));
        }
        self::assertCount(CallbackController::CALLBACK_ASKS, $this->cubePay->callsTo(CubePayGateway::VERIFY), 'the rest answered from the payment as it stands');

        // Paid meanwhile: answered as the payment stands until the minute is over — a browser too —, then asked.
        $this->cubePay->pay((string) $payment->reference);
        self::assertSame(['paid' => false], $this->decode($this->send('POST', $callback)));
        self::assertStringContainsString('هنوز تایید نشده است', (string) $this->send('GET', $callback, null, ['Accept' => 'text/html'])->getBody());
        Carbon::setTestNow(now()->addSeconds(61));
        self::assertSame(['paid' => true], $this->decode($this->send('POST', $callback)));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function testTheReturnPageSaysWhatThePaymentCameToAndWhereToLook(): void
    {
        $this->config(['telegram.username' => 'amo_shop_bot']);
        // Paid after its order was cancelled: the money went to the wallet, which the page says, not "order paid".
        $late = $this->topUp('50000')->payment();
        $this->service(PaymentService::class)->cancelWithOrder($late, 'admin', null);
        $this->cubePay->pay((string) $late->reference);
        $html = (string) $this->send('GET', $this->callbackPath($late), null, ['Accept' => 'text/html'])->getBody();
        self::assertStringContainsString('مبلغ به کیف پول شما اضافه شد', $html);
        self::assertStringNotContainsString('پرداخت شما تایید شد', $html);
        self::assertStringContainsString('href="https://t.me/amo_shop_bot"', $html);

        // A customer of the website alone is never sent to the bot: to their account on the site, where they see the order.
        $this->customer = $this->webCustomer();
        $payment = $this->topUp('30000')->payment();
        $html = (string) $this->send('GET', $this->callbackPath($payment), null, ['Accept' => 'text/html'])->getBody();
        self::assertStringContainsString('در حساب خود در سایت فروشگاه می‌بینید', $html);
        self::assertStringNotContainsString('ربات', $html);
        self::assertStringNotContainsString('<a ', $html, 'no way back it could name: Payments knows no website address');

        // Closed: no promise that a bank gives the money back — support, with the order's number.
        $this->cubePay->close((string) $payment->reference);
        Carbon::setTestNow(now()->addSeconds(61));
        $html = (string) $this->send('GET', $this->callbackPath($payment), null, ['Accept' => 'text/html'])->getBody();
        self::assertStringContainsString('پرداخت انجام نشد', $html);
        self::assertStringContainsString('با شماره سفارش به پشتیبانی پیام بدهید', $html);
        self::assertStringNotContainsString('بانک', $html);
    }

    public function testAPageSettledByAnotherCallAsTheCheckoutAsksIsNotShownTwice(): void
    {
        $first = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $first->reference);
        Carbon::setTestNow(now()->addMinutes(28));
        // Its callback settles it while the checkout's own ask is on its way, and tells the customer.
        $this->meanwhileVerify(fn() => $this->service(OnlinePayments::class)->check(Payment::query()->findOrFail($first->id)));

        $again = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Closed, $again->outcome, 'the callback\'s notice was the word on it');
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentSettled->value)->count());
        self::assertSame('50000.00', $this->customer->balance());
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE));
    }

    public function testAPagePaidAsItsOrderClosesTellsTheCustomerTheMoneyWentToTheWallet(): void
    {
        $first = $this->topUp('50000')->payment();
        $this->cubePay->pay((string) $first->reference);
        Carbon::setTestNow(now()->addMinutes(28));
        // Support cancels the order while the checkout's own ask is on its way.
        $this->meanwhileVerify(fn() => $this->service(PaymentService::class)->cancelWithOrder(Payment::query()->findOrFail($first->id), 'admin', null));

        $again = $this->topUp('50000');

        self::assertSame(CheckoutOutcome::Closed, $again->outcome);
        self::assertTrue($first->refresh()->wentToWallet());
        self::assertSame('50000.00', $this->customer->balance());
        self::assertSame(1, Notification::query()->where('type', NoticeType::PaymentCredited->value)->count(), 'the checkout\'s answer does not say it');
        self::assertCount(1, $this->cubePay->callsTo(CubePayGateway::CREATE));
    }

    public function testAnOrderWhosePageStillTakesMoneyDoesNotExpire(): void
    {
        $payment = $this->topUp('50000')->payment();
        $expiry = $this->service(PaymentService::class);

        self::assertFalse($expiry->expire($payment->order, now()->addMinute()), 'a discount code\'s short hold, or an idle checkout: the page is open');
        Carbon::setTestNow(now()->addMinutes(31));
        self::assertTrue($expiry->expire($payment->order, now()->addMinute()), 'its page closed');
        self::assertSame(OrderStatus::Cancelled, $payment->order->refresh()->status);
    }

    public function testATokenThatNoLongerDecryptsReadsAsNoneAndRefusesTheCheckout(): void
    {
        $logs = $this->logs();
        $this->method->forceFill(['config' => ['token' => 'encrypted:bm90LWEtcGF5bG9hZA==']])->save();

        self::assertSame(['set' => false, 'hint' => ''], array_intersect_key((array) $this->service(PaymentMethods::class)->presentOne($this->method->refresh())['config']->token, ['set' => 1, 'hint' => 1]));
        self::assertSame(CheckoutOutcome::Refused, $this->topUp('50000')->outcome);
        self::assertSame([], $this->cubePay->calls, 'no call without a token');
        self::assertTrue($logs->hasWarningThatContains('cannot be decrypted'));
        self::assertTrue($logs->hasWarningThatContains('has no API token'));
    }

    /** What a top-up of the same order now throws; null when it goes through. */
    private function checkoutRefusal(): ?\Throwable
    {
        try {
            $this->topUp('50000');
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
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

    /** `$meanwhile` runs as the next verification reaches CubePay, before CubePay answers it: another call at that moment. */
    private function meanwhileVerify(\Closure $meanwhile): void
    {
        $cubePay = $this->cubePay;
        $done = false;
        $transport = $this->app()->container()->get('http.transport');
        self::assertInstanceOf(HandlerStack::class, $transport);
        $transport->setHandler(static function (RequestInterface $request, array $options) use ($cubePay, $meanwhile, &$done): PromiseInterface {
            if (!$done && strtok((string) $request->getUri(), '?') === CubePayGateway::VERIFY) {
                $done = true;
                $meanwhile();
            }

            return $cubePay($request, $options);
        });
    }
}
