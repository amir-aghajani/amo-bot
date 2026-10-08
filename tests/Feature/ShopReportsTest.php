<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Providers\Services\ServerHealth;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\DeliveryRetry;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * What the shop tells its report group, topic by topic: a sale and how it was paid (and what it earned a referrer), a
 * renewal and the new deadline, a charged wallet and its balance, an agent's traffic bought, a receipt and every
 * verdict on it, a newcomer and whoever brought them, a delivery that failed and the way out, a panel that stopped
 * answering and answers again — each in the group of the bot it happened in (an agent's sale in the agent's; the
 * servers' and the agency's news in the shop's own). Words it cannot compose cost only the report; a database that
 * refuses one fails what it reports, in its transaction — never carried on as if it stood.
 */
final class ShopReportsTest extends BotTestCase
{
    private const ALI = '<a href="tg://user?id=5151">Ali</a> · @ali · <code>5151</code>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePanel();
        $this->withoutQr();
        $this->reportGroup();
    }

    public function testASaleIsReportedWithWhatWasSoldAndHowItWasPaid(): void
    {
        $server = $this->sellingServer();
        $order = $this->buy($this->customer(['username' => 'ali']), $this->plan([], $server), $server);

        self::assertSame(implode("\n", [
            "🛍️ <b>خرید جدید</b> · سفارش #{$order->id}",
            '👤 ' . self::ALI,
            '📦 پلن: یک‌ماهه',
            '📍 لوکیشن: آلمان',
            '🔖 سرویس: <code>ali_1</code>',
            '💳 مبلغ: ۱۲۰٬۰۰۰ تومان · کیف پول',
        ]), $this->reported(Topic::Purchases));
        self::assertSame([], $this->reportedIn(Topic::Receipts), 'a wallet payment has no receipt to report');
    }

    public function testACardSaleCarriesItsReceiptItsVerdictAndWhatItEarnedTheReferrer(): void
    {
        $this->referralProgram(rate: 10);
        $reza = $this->customer(['telegram_id' => 7070, 'first_name' => 'Reza', 'username' => 'reza']);
        $ali = $this->customer(['username' => 'ali', 'referred_by' => $reza->id]);
        $server = $this->sellingServer();
        $payment = $this->paidByCard($this->purchaseOrder($ali, $this->plan([], $server), $server));

        $sale = $this->reported(Topic::Purchases);
        self::assertStringContainsString('💳 مبلغ: ۱۲۰٬۰۰۰ تومان · کارت به کارت (ملت)', $sale);
        self::assertStringEndsWith('👥 پورسانت معرف: ۱۲٬۰۰۰ تومان برای <a href="tg://user?id=7070">Reza</a> · @reza · <code>7070</code>', $sale);

        [$receipt, $verdict] = ReportMessage::query()->where('topic', Topic::Receipts->value)->oldest('id')->get()->all();
        self::assertSame("receipt:{$payment->id}", $receipt->ref);
        self::assertSame([self::TELEGRAM_ID, self::RECEIPT_MESSAGE], [$receipt->copy_chat_id, $receipt->copy_message_id], 'the customer\'s own picture is copied');
        self::assertStringContainsString("📦 خرید یک‌ماهه · آلمان · سفارش #{$payment->order_id}", $receipt->text);
        self::assertStringContainsString('⏳ در انتظار بررسی در پنل مدیریت', $receipt->text, 'no window: only the admin decides');
        self::assertSame("receipt:{$payment->id}", $verdict->reply_ref);
        self::assertSame("✅ رسید پرداخت #{$payment->id} تایید شد.", $verdict->text);
    }

    public function testATopUpIsReportedWithTheNewBalance(): void
    {
        $order = $this->topUpOrder($this->customer(['username' => 'ali']), '250000');
        $this->paidByCard($order);

        self::assertSame(implode("\n", [
            "💰 <b>شارژ کیف پول</b> · سفارش #{$order->id}",
            '👤 ' . self::ALI,
            '💳 مبلغ: ۲۵۰٬۰۰۰ تومان · کارت به کارت (ملت)',
            '👛 موجودی: ۲۵۰٬۰۰۰ تومان',
        ]), $this->reported(Topic::Wallet));
    }

    public function testARenewalIsReportedWithTheServiceAndItsNewEnd(): void
    {
        $ali = $this->customer(['username' => 'ali']);
        $server = $this->sellingServer();
        $plan = $this->plan([], $server);
        $this->buy($ali, $plan, $server);
        $subscription = Subscription::query()->sole();

        $this->service(WalletService::class)->credit($ali, $plan->price, 'test');
        $order = $this->service(OrderService::class)->createRenewal($ali, $subscription, $plan);
        $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status, (string) $order->notes);

        $subscription->refresh();
        self::assertSame(implode("\n", [
            "♻️ <b>تمدید سرویس</b> · سفارش #{$order->id}",
            '👤 ' . self::ALI,
            '🔖 سرویس: <code>ali_1</code> · یک‌ماهه · آلمان',
            '📅 پایان: ' . Messages::expiry($subscription->expires_at, $subscription->duration_days),
            '💳 مبلغ: ۱۲۰٬۰۰۰ تومان · کیف پول',
        ]), $this->reported(Topic::Renewals));
    }

    public function testADeliveryThatFailsIsAnErrorWithItsReasonAndTheSaleComesWithTheRetry(): void
    {
        $server = $this->sellingServer();
        $order = $this->purchaseOrder($this->customer(['username' => 'ali']), $this->plan([], $server), $server);
        FakeProvider::$unreachable = true;

        $this->paidByCard($order);

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        $error = $this->reported(Topic::Errors);
        self::assertStringStartsWith("⚠️ <b>تحویل سفارش #{$order->id} ناموفق بود</b> (خرید)\n👤 " . self::ALI . "\n📍 سرور: آلمان\n", $error);
        self::assertNotSame($order->notes, $order->diagnosis);
        self::assertStringContainsString('❗️ علت: ' . htmlspecialchars((string) $order->diagnosis), $error, "the main bot's group is the owner's: the diagnosis");
        self::assertStringEndsWith('«تلاش دوباره برای تحویل» را بزنید — همین‌جا یا در صفحه «سفارش‌ها» در پنل.', $error);
        $failure = ReportMessage::query()->where('topic', Topic::Errors->value)->sole();
        self::assertSame(DeliveryRetry::buttons($order->id), $failure->keyboard, 'the bot admins\' retry under it');
        self::assertSame(["delivery:{$order->id}", "delivery:{$order->id}", true], [$failure->ref, $failure->reply_ref, $failure->clears_buttons], 'a failure after an earlier one answers it and takes its button');
        self::assertSame([], $this->reportedIn(Topic::Purchases));

        FakeProvider::$unreachable = false;
        $this->service(OrderActions::class)->retry($order);
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertCount(1, $this->reportedIn(Topic::Purchases), 'delivered by the retry');
        $delivered = ReportMessage::query()->where('topic', Topic::Errors->value)->latest('id')->firstOrFail();
        self::assertSame(["✅ سفارش #{$order->id} با تلاش دوباره تحویل شد.", "delivery:{$order->id}", true], [$delivered->text, $delivered->reply_ref, $delivered->clears_buttons], 'answered under the failure, its button gone');

        try {
            $this->service(OrderActions::class)->retry($order);
            self::fail('A delivered order was delivered again.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->errors());
            self::assertCount(1, $this->reportedIn(Topic::Purchases), 'nothing new to report');
        }
    }

    public function testEveryVerdictOnAReceiptAnswersItAndAPaymentWithoutOneHasNothingToAnswer(): void
    {
        $ali = $this->customer();
        $method = $this->cardMethod();
        $payments = $this->service(PaymentService::class);

        $rejected = $this->receipt($this->cardPayment($this->topUpOrder($ali, '50000'), $method));
        $payments->reject($rejected, 'admin', 'مبلغ کم است');
        $cancelled = $this->receipt($this->cardPayment($this->topUpOrder($ali, '60000'), $method));
        $payments->cancel($cancelled, 'admin', 'تکراری');
        $automatic = $this->receipt($this->cardPayment($this->topUpOrder($ali, '70000'), $method));
        self::assertTrue($this->service(PaymentActions::class)->autoApprove($automatic));
        $payments->cancel($this->cardPayment($this->topUpOrder($ali, '80000'), $method), 'admin', null);
        $payments->reject($rejected, 'admin', 'دوباره'); // decided already: nothing new

        self::assertSame([
            ["receipt:{$rejected->id}", "❌ رسید پرداخت #{$rejected->id} رد شد.\n📝 توضیح پشتیبانی: مبلغ کم است"],
            ["receipt:{$cancelled->id}", "🚫 پرداخت #{$cancelled->id} لغو شد.\n📝 توضیح پشتیبانی: تکراری"],
            ["receipt:{$automatic->id}", "✅ رسید پرداخت #{$automatic->id} خودکار تایید شد؛ در مهلت بررسی کسی آن را تایید یا رد نکرد."],
        ], ReportMessage::query()->whereNotNull('reply_ref')->oldest('id')->get()->map(static fn(ReportMessage $row): array => [$row->reply_ref, $row->text])->all());
    }

    public function testANewcomerIsReportedWithWhoeverBroughtThemAndOnlyOnce(): void
    {
        $this->referralProgram();
        $reza = $this->customer(['telegram_id' => 7070, 'first_name' => 'Reza', 'username' => 'reza']);
        $code = $this->service(ReferralService::class)->codeFor($reza);

        $this->send($this->message('/start ref_' . $code, ['from' => ['id' => 8080, 'first_name' => 'Sara']], 8080));
        $this->send($this->message('/start', ['from' => ['id' => 8080, 'first_name' => 'Sara']], 8080));

        self::assertSame(implode("\n", [
            '👤 <b>کاربر جدید</b>',
            '<a href="tg://user?id=8080">Sara</a> · بدون نام کاربری · <code>8080</code>',
            '👥 معرف: <a href="tg://user?id=7070">Reza</a> · @reza · <code>7070</code>',
        ]), $this->reported(Topic::Users), 'reported once, when they first came');
    }

    public function testAPanelThatStopsAnsweringAndAnswersAgainIsReportedOnceEach(): void
    {
        $health = $this->service(ServerHealth::class);
        $server = $this->fakeServer('آلمان', ['last_checked_at' => now()->subHour()]);
        $failure = new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out');

        $health->contacted($server, $failure);
        $health->contacted($server, $failure);
        $health->contacted($server, null);
        $health->contacted($server, null);
        $health->contacted($this->fakeServer('هلند'), $failure);

        self::assertSame([
            "🔴 <b>پنل سرور «آلمان» جواب نمی‌دهد</b>\n❗️ علت: " . htmlspecialchars(ProviderErrorPresenter::describe($failure)),
            '🟢 <b>پنل سرور «آلمان» دوباره جواب می‌دهد</b>',
        ], $this->reportedIn(Topic::Errors), 'the first check of a new server is nobody\'s news');
    }

    public function testAnAgentsTrafficIsTheShopsOwnAgencyNews(): void
    {
        $agent = $this->agent(overrides: ['telegram_id' => 7007, 'first_name' => 'Mina', 'username' => 'mina']);
        $this->agentBot($agent, traffic: 0);
        $order = $this->service(OrderService::class)->openTraffic($agent, 20);

        $this->paidByCard($order);

        self::assertSame(implode("\n", [
            "💾 <b>خرید حجم نمایندگی</b> · سفارش #{$order->id}",
            '👤 <a href="tg://user?id=7007">Mina</a> · @mina · <code>7007</code>',
            '📦 حجم: ' . Messages::bytes(Traffic::bytesOfGb(20)),
            '💳 مبلغ: ' . Money::format($order->amount) . ' · کارت به کارت (ملت)',
            '🗄 حجم باقی‌مانده نماینده: ' . Messages::bytes(Traffic::bytesOfGb(20)),
        ]), $this->reported(Topic::Agency));
    }

    public function testASaleInAnAgentsBotGoesToTheAgentsGroupAndAServersNewsToTheShopsOwn(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, fn() => $this->reportGroup(-1009999));
        $server = $this->sellingServer();

        CurrentBot::run($bot, fn(): Order => $this->buy($this->customer(['telegram_id' => 3003, 'first_name' => 'Reza']), $this->plan(['traffic_gb' => 10], $server), $server));
        self::assertCount(1, CurrentBot::run($bot, fn(): array => $this->reportedIn(Topic::Purchases)), "the agent's own group");
        self::assertSame([], $this->reportedIn(Topic::Purchases), "the shop's group hears nothing of the agent's sales");

        // Whatever shop the code works in, a server is the shop's own news.
        CurrentBot::run($bot, fn() => $this->service(ShopReports::class)->serverDown($server, 'Connection timed out'));
        self::assertCount(1, $this->reportedIn(Topic::Errors));
        self::assertSame([], CurrentBot::run($bot, fn(): array => $this->reportedIn(Topic::Errors)));
    }

    public function testADeliveryAnAgentsBotFailedIsSaidToItsGroupInAWord(): void
    {
        $bot = $this->agentBot();
        CurrentBot::run($bot, fn() => $this->reportGroup(-1009999));
        $server = $this->sellingServer();
        FakeProvider::$unreachable = true;

        $order = CurrentBot::run($bot, fn(): Order => $this->paidByCard($this->purchaseOrder($this->customer(['telegram_id' => 3003]), $this->plan(['traffic_gb' => 10], $server), $server))->order);

        self::assertSame(OrderStatus::Failed, $order->refresh()->status);
        $error = CurrentBot::run($bot, fn(): string => $this->reported(Topic::Errors));
        self::assertStringContainsString('❗️ علت: ' . htmlspecialchars((string) $order->notes), $error, "an agent's group reads what happened in a word");
        self::assertStringNotContainsString((string) $order->diagnosis, $error, "never the owner's diagnosis");
    }

    public function testAReportWhoseWordsCannotBeComposedIsLoggedAndLetGo(): void
    {
        $logs = $this->logs();

        // A server without a name: the report's words cannot be made of it — a mistake of the report's own, not the database's.
        $this->service(ShopReports::class)->serverDown(new Server(), 'Connection timed out');

        self::assertSame(0, ReportMessage::query()->count());
        self::assertTrue($logs->hasErrorThatContains('was not queued'), 'logged for the shop to see — and what reported goes on');
    }

    public function testAReceiptsReportTheDatabaseRefusesTakesWhatItReportsBackWithIt(): void
    {
        $payments = $this->service(PaymentService::class);
        $customer = $this->customer();
        $method = $this->cardMethod();

        $sent = $this->cardPayment($this->topUpOrder($customer, '250000'), $method);
        $this->refusingReports(fn() => $payments->submitReceipt($sent, 'receipt-1', null, null, self::RECEIPT_MESSAGE));
        self::assertSame([PaymentStatus::Pending, null], [$sent->refresh()->status, $sent->receipt_at], 'sent: the payment still waits for its receipt');

        foreach ([
            'approved' => static fn(Payment $payment) => $payments->approve($payment, 'admin'),
            'rejected' => static fn(Payment $payment) => $payments->reject($payment, 'admin', null),
            'cancelled' => static fn(Payment $payment) => $payments->cancel($payment, 'admin', null),
        ] as $decision => $decide) {
            $payment = $this->receipt($this->cardPayment($this->topUpOrder($customer, '250000'), $method));
            $this->refusingReports(static fn() => $decide($payment));

            self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, "{$decision}: not carried on as if it stood");
            self::assertSame(OrderStatus::Pending, $payment->order->refresh()->status, $decision);
        }
        self::assertSame('0.00', $customer->balance(), 'no top-up charged');
    }

    public function testADeliveryWhoseReportTheDatabaseRefusesIsNoDeliveryYet(): void
    {
        $server = $this->sellingServer();
        $customer = $this->customer();
        $plan = $this->plan([], $server);
        $this->wallet($customer, $plan->price);
        $order = $this->service(OrderService::class)->openPurchase($customer, $plan, $server);

        $this->refusingReports(fn() => $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod()));

        // Paid, and nobody delivering it — neither delivered nor failed: what a resumed delivery takes up.
        self::assertSame(OrderStatus::Processing, $order->refresh()->status);
        self::assertSame(0, Subscription::query()->count(), 'no service the shop does not know it sold');
        self::assertCount(1, FakeProvider::$deleted, "the panel's client taken back");

        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES + 1));
        self::assertTrue($this->service(OrderService::class)->resume($order->refresh()));
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertStringContainsString("سفارش #{$order->id}", $this->reported(Topic::Purchases), 'reported as it is delivered at last');
    }

    public function testATicketsReportTheDatabaseRefusesTakesItsChangeBack(): void
    {
        $tickets = $this->service(Tickets::class);
        $ticket = $this->ticket($this->customer());

        $this->refusingReports(fn() => $tickets->close($ticket, null));
        self::assertSame(TicketStatus::Open, $ticket->refresh()->status, 'closed: not carried on as if it stood');

        $tickets->close($ticket, null);
        $this->refusingReports(fn() => $tickets->reopen($ticket, $this->panelActor()));
        self::assertSame(TicketStatus::Closed, $ticket->refresh()->status, 'opened again: not carried on');

        $this->refusingReports(fn() => $tickets->rate($ticket, ['rating' => 5]));
        self::assertNull($ticket->refresh()->rating, 'rated: not carried on');
    }

    public function testAServersNewsTheDatabaseRefusesIsNoticedAgainAtTheNextContact(): void
    {
        $server = $this->sellingServer();
        $health = $this->service(ServerHealth::class);
        $health->record($server, null);

        $this->refusingReports(fn() => $health->record($server, 'Connection timed out'));
        self::assertNull($server->refresh()->last_error, 'gone down: not carried on as if it stood');

        $health->record($server, 'Connection timed out');
        self::assertStringContainsString('جواب نمی‌دهد', $this->reported(Topic::Errors), 'the next contact notices it again');
    }

    public function testANewcomerWhoseReportTheDatabaseRefusesIsRegisteredAtTheirNextArrival(): void
    {
        $customers = $this->service(Customers::class);
        $profile = Customers::profile('newbie', 'New', null);

        $this->refusingReports(fn() => $customers->byTelegram(4242, $profile, null));
        self::assertFalse(User::query()->where('telegram_id', 4242)->exists(), 'not carried on as if it stood');

        $customers->byTelegram(4242, $profile, null);
        self::assertStringContainsString('@newbie', $this->reported(Topic::Users));
    }

    /**
     * `$work` while the database refuses every report — its disk failed, say —: its refusal goes up to the caller, whose
     * transaction it was.
     *
     * @param \Closure(): mixed $work
     */
    private function refusingReports(\Closure $work): void
    {
        $refused = static fn(): never => throw new QueryException('sqlite', 'insert into "report_messages"', [], new \PDOException('SQLSTATE[HY000]: General error: 10 disk I/O error'));
        try {
            $this->whileListening('eloquent.saving: ' . ReportMessage::class, $refused, $work);
            self::fail("the database's refusal went untold");
        } catch (QueryException) {
            // Refused as the database refused it.
        }
    }

    /** The one report queued for the topic. */
    private function reported(Topic $topic): string
    {
        return ReportMessage::query()->where('topic', $topic->value)->sole()->text;
    }

    /** @return list<string> What was queued for the topic, oldest first. */
    private function reportedIn(Topic $topic): array
    {
        return ReportMessage::query()->where('topic', $topic->value)->oldest('id')->pluck('text')->all();
    }
}
