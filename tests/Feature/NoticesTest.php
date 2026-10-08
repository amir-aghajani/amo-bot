<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounts\Services\TwoFactor;
use App\Modules\Accounts\Tasks\PruneAccountsTask;
use App\Modules\Agency\DTO\AgencyTerms;
use App\Modules\Agency\Services\AgencyActions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Enums\NoticeSubject;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Notifications\Services\Notices;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\SubscriptionActions;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Subscriptions\Tasks\SendRemindersTask;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Texts\WebText;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\Customers;
use App\Support\Money;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Symfony\Component\Mime\Email;
use Tests\Fakes\FakeProvider;
use Tests\Fakes\RecordingMailTransport;
use Tests\HttpTestCase;

/**
 * Every notice the shop sends a customer reaches them on every door they have (Notifications\Services\Notices): it is
 * kept for their website — what it was, what it is about (the order of a payment, an order, a service, a support ticket;
 * nothing for their account or another's row) and its words, exactly what the bot wrote them —, it goes to their Telegram chat when
 * they have one that has not turned the bot away, else by email when they have an address and the shop's email goes out (its subject the notice's kind,
 * its body the words as safe HTML and plain text), and else nowhere but the website, as before. Half a year on, the
 * hourly housekeeping forgets it.
 */
final class NoticesTest extends HttpTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->withoutQr();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
    }

    public function testEveryNoticeOfAPaymentIsKeptWithItsOrder(): void
    {
        $payments = $this->service(PaymentActions::class);
        $card = $this->cardMethod();

        $approved = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $card));
        $payments->approve($approved, $this->panelActor());
        $this->assertKept($this->ali, NoticeType::PaymentSettled, $approved->order);
        self::assertStringContainsString('<code>ali_1</code>', $this->latest($this->ali)->text, 'the service delivered, as the bot words it');

        $rejected = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000'), $card));
        $payments->reject($rejected, $this->panelActor(), 'مبلغ نمی‌خواند');
        $this->assertKept($this->ali, NoticeType::PaymentRejected, $rejected->order);

        $reminded = $this->cardPayment($rejected->order, $card);
        self::assertSame(Delivery::Told, $payments->remind($reminded));
        $this->assertKept($this->ali, NoticeType::PaymentReminder, $reminded->order);

        $cancelled = $this->cardPayment($this->topUpOrder($this->ali, '70000'), $card);
        $payments->cancel($cancelled, $this->panelActor(), null);
        $this->assertKept($this->ali, NoticeType::OrderCancelled, $cancelled->order);

        $payments->refund($approved->refresh(), $this->panelActor(), 'درخواست مشتری');
        $this->assertKept($this->ali, NoticeType::PaymentRefunded, $approved->order);

        $topUp = $this->paidByCard($this->topUpOrder($this->ali, '250000'));
        $payments->refund($topUp, $this->panelActor(), null);
        $this->assertKept($this->ali, NoticeType::TopUpRefunded, $topUp->order);

        self::assertSame(6, Notification::query()->where('user_id', $this->ali->id)->count(), 'one a notice');
    }

    public function testAServiceDeliveredAsItsQrCardIsKeptAsItsCaption(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('The QR card needs GD.');
        }
        $this->service(Settings::class)->set('bot.qr_enabled', true);
        $payment = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $this->plan, $this->server), $this->cardMethod()));
        $this->telegram()->reset();

        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());

        self::assertSame(['sendPhoto'], $this->telegram()->calls(), 'the QR card');
        $this->assertKept($this->ali, NoticeType::PaymentSettled, $payment->order);
    }

    public function testEveryNoticeOfAServiceIsKeptWithTheServiceAndOneDeletedWithNothing(): void
    {
        $subscriptions = $this->service(SubscriptionActions::class);
        $service = $this->mirrored('ali_1');

        $subscriptions->disable($service, $this->panelActor(), 'استفاده خارج از قوانین');
        $this->assertKept($this->ali, NoticeType::ServiceDisabled, $service);

        $subscriptions->enable($service->refresh(), $this->panelActor());
        $this->assertKept($this->ali, NoticeType::ServiceEnabled, $service);

        $subscriptions->extend($service->refresh(), $this->panelActor(), ['days' => 3, 'traffic_gb' => 5, 'note' => 'جبران قطعی']);
        $this->assertKept($this->ali, NoticeType::ServiceGranted, $service);

        $target = $this->fakeServer('هلند');
        $this->inbound($target, '7');
        $subscriptions->move($service->refresh(), $this->panelActor(), $target->id, false);
        $this->assertKept($this->ali, NoticeType::ServiceMoved, $service);

        $subscriptions->delete($service->refresh(), $this->panelActor(), 'درخواست خود مشتری', false);
        $this->assertKept($this->ali, NoticeType::ServiceDeleted, null);
    }

    public function testTheRemindersAreKeptWithTheirService(): void
    {
        $this->botSettings('reminders', ['expiry_reminder' => true, 'expiry_reminder_days' => 3, 'traffic_reminder' => true, 'traffic_reminder_percent' => 80]);
        $ending = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDay()]);
        $low = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['download_bytes' => 25 * Traffic::GIGABYTE]);

        $this->service(SendRemindersTask::class)->run();

        $kept = Notification::query()->where('user_id', $this->ali->id)->orderBy('id')->get();
        self::assertSame([
            [NoticeType::ExpiryReminder, NoticeSubject::Subscription, $ending->id],
            [NoticeType::TrafficReminder, NoticeSubject::Subscription, $low->id],
        ], $kept->map(static fn(Notification $notice): array => [$notice->type, $notice->subject_type, $notice->subject_id])->all());
        self::assertSame($this->telegram()->sentTo(self::TELEGRAM_ID), $kept->pluck('text')->all(), 'in the words the bot wrote them');
    }

    public function testTheAutomaticRenewalsNoticesAreKept(): void
    {
        $renewing = $this->due('ali_1');
        $this->wallet($this->ali, '50000');
        $this->service(AutoRenewTask::class)->run();
        $this->assertKept($this->ali, NoticeType::AutoRenewShort, $renewing);

        $this->wallet($this->ali, '300000');
        $this->service(AutoRenewTask::class)->run();
        $this->assertKept($this->ali, NoticeType::AutoRenewed, Order::query()->sole());

        $failing = $this->due('ali_2');
        FakeProvider::$refusing = [$this->server->id => ['updateClient']];
        $this->service(AutoRenewTask::class)->run();
        $this->assertKept($this->ali, NoticeType::RenewalFailed, Order::query()->where('subscription_id', $failing->id)->sole());
    }

    public function testAReferrersNoticesAreAboutNothingOfTheirOwn(): void
    {
        $this->referralProgram();
        $code = $this->service(ReferralService::class)->codeFor($this->ali);

        $friend = $this->service(Customers::class)->byTelegram(7_000_001, ['username' => 'reza', 'first_name' => 'Reza', 'last_name' => null], $code);
        $this->assertKept($this->ali, NoticeType::ReferralJoined, null);

        $payment = $this->receipt($this->cardPayment($this->purchaseOrder($friend, $this->plan, $this->server), $this->cardMethod()));
        $this->service(PaymentActions::class)->approve($payment, $this->panelActor());
        $this->assertKept($this->ali, NoticeType::ReferralCommission, null);
        self::assertSame([NoticeType::PaymentSettled, NoticeSubject::Order], [$this->latest($friend)->type, $this->latest($friend)->subject_type], 'the friend hears of their own payment');
    }

    public function testTheAgencysNoticesAreKeptAndAnAgentHearsOfTheirBotsOrderAboutNothingOfTheirOwn(): void
    {
        $this->agencyProgram();
        $gold = $this->agencyLevel();
        $actions = $this->service(AgencyActions::class);

        $actions->reject($this->agencyRequest($this->ali), $this->panelActor(), 'فعلا نه');
        $this->assertKept($this->ali, NoticeType::AgencyRejected, null);

        $actions->approve($this->agencyRequest($this->ali), $this->panelActor(), $gold, null);
        $agent = $this->ali->refresh();
        $this->assertKept($agent, NoticeType::AgencyApproved, null);

        self::assertSame(Delivery::Told, $actions->change($agent, new AgencyTerms($this->agencyLevel(['name' => 'نقره‌ای']), '50000')));
        $this->assertKept($agent, NoticeType::AgencyChanged, null);

        // Their bot cannot deliver its customer's renewal: the agent hears it in the main bot.
        $bot = $this->agentBot($agent, traffic: 10);
        $order = CurrentBot::run($bot, function (): Order {
            $this->withoutQr();
            $plan = $this->plan(['traffic_gb' => 30], $this->server);
            $subscription = $this->subscription($this->customer(['telegram_id' => 7_000_002]), $plan, $this->server, 'mina_1');
            $order = $this->service(OrderService::class)->createRenewal($this->wallet($subscription->user, $plan->price), $subscription, $plan);
            $this->service(PaymentService::class)->createForOrder($order, $this->walletMethod());

            return $order->refresh();
        });
        self::assertSame(OrderStatus::Failed, $order->status);
        $this->assertKept($agent, NoticeType::AgencyTrafficShort, null);
        self::assertSame(CurrentBot::main()->id, $this->latest($agent)->bot_id, "in the main bot's shop, the agent's own");

        $actions->revoke($agent->refresh(), 'همکاری تمام شد');
        $this->assertKept($agent, NoticeType::AgencyRevoked, null);
    }

    public function testACustomerWithoutTelegramIsToldByEmailInTheBotsWordsAndThePanelSaysSo(): void
    {
        $mail = $this->mail();
        $sara = $this->webCustomer();
        $payment = $this->cardPayment($this->topUpOrder($sara, '50000.00'), $this->cardMethod());
        $this->loginAsAdmin();

        $reminder = $this->postJson("/api/admin/payments/{$payment->id}/remind");

        self::assertSame('emailed', $this->decode($reminder)['delivery'], 'the panel says it went by email');
        self::assertSame([], $this->telegram()->calls(), 'Telegram is not asked');
        $notice = $this->latest($sara);
        self::assertSame([NoticeType::PaymentReminder, NoticeSubject::Order, $payment->order_id], [$notice->type, $notice->subject_type, $notice->subject_id]);
        self::assertSame(self::text(BotText::PaymentReminder, ['order' => (string) $payment->order_id, 'amount' => Money::format('50000.00')]), $notice->text, "the bot's words, as the bot would have sent them");

        $email = $mail->to(self::WEB_EMAIL)[0] ?? self::fail('No email went to her.');
        self::assertCount(1, $mail->sent());
        self::assertSame(NoticeType::PaymentReminder->subject(), $email->getSubject());
        self::assertStringContainsString(WebText::html($notice->text), (string) $email->getHtmlBody(), 'the notice, as safe HTML');
        self::assertStringContainsString(WebText::plain($notice->text), (string) $email->getTextBody(), 'and as plain text beside it');
        self::assertStringContainsString('<h1 style="margin:0 0 16px;font-size:18px;line-height:1.6;">' . NoticeType::PaymentReminder->subject() . '</h1>', (string) $email->getHtmlBody(), "in the look of the shop's every email");
    }

    public function testANoticeEmailedCarriesItsFormattingSafelyAndTheShopsName(): void
    {
        $sara = $this->webCustomer();
        $this->twoFactorOn($this->website(), $sara);
        $mail = $this->mail();

        $this->service(TwoFactor::class)->turnOff($sara->refresh(), $this->panelActor());

        $email = $this->emailOf($mail, $sara);
        self::assertSame(NoticeType::TwoFactorDisabled->subject(), $email->getSubject());
        self::assertSame($this->service(Bots::class)->name(CurrentBot::main()), $email->getFrom()[0]->getName(), 'from the shop');
        self::assertStringContainsString('ورود دو مرحله‌ای حساب شما در وب‌سایت توسط پشتیبانی خاموش شد.<br><br>', (string) $email->getHtmlBody(), 'its lines, as the bot breaks them');
        $this->assertKept($sara, NoticeType::TwoFactorDisabled, null);
    }

    public function testWithNeitherDoorOpenTheNoticeIsStillKeptForTheWebsite(): void
    {
        $sara = $this->webCustomer();
        $payment = $this->cardPayment($this->topUpOrder($sara, '50000.00'), $this->cardMethod());
        $payments = $this->service(PaymentActions::class);

        self::assertSame(Delivery::NoTelegram, $payments->remind($payment), "the shop's email is not set up");
        $mail = $this->mail();
        $sara->forceFill(['email' => null, 'password_hash' => null, 'google_sub' => 'sara-google'])->save();
        self::assertSame(Delivery::NoTelegram, $payments->remind($payment->refresh()), 'nor has she an address: a Google account signs her in');

        self::assertSame([], $this->telegram()->calls());
        self::assertSame([], $mail->sent());
        self::assertSame([NoticeType::PaymentReminder, NoticeType::PaymentReminder], Notification::query()->where('user_id', $sara->id)->pluck('type')->all(), 'both kept, nobody told');
    }

    public function testACustomerWithTelegramGetsNoEmail(): void
    {
        $mail = $this->mail();
        $this->ali->forceFill(['email' => 'ali@example.com'])->save();

        $this->service(CustomerNotifier::class)->serviceEnabled($this->mirrored('ali_1'));

        self::assertSame(['sendMessage'], $this->telegram()->calls());
        self::assertSame([], $mail->sent(), 'Telegram is their door');
        $this->assertKept($this->ali, NoticeType::ServiceEnabled, Subscription::query()->sole());
    }

    public function testACustomerWhoTurnedTheBotAwayIsToldByEmail(): void
    {
        $mail = $this->mail();
        $this->ali->forceFill(['email' => 'ali@example.com'])->save();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->cardMethod());
        $payments = $this->service(PaymentActions::class);

        // Learned as it is sent: Telegram says they blocked the bot — their email is the door left.
        $this->telegram()->fail(403, 'Forbidden: bot was blocked by the user');
        self::assertSame(Delivery::Emailed, $payments->remind($payment));
        self::assertTrue($this->ali->refresh()->bot_blocked);
        self::assertSame([NoticeType::PaymentReminder->subject()], array_map(static fn(Email $email): string => (string) $email->getSubject(), $mail->to('ali@example.com')));

        // Known before: Telegram is not asked, the email goes.
        $this->telegram()->reset();
        self::assertSame(Delivery::Emailed, $payments->remind($payment->refresh()));
        self::assertSame([], $this->telegram()->calls());
        self::assertCount(2, $mail->to('ali@example.com'));

        // Without an address no door is open: turned away, as before — and kept for the website all the same.
        $this->ali->forceFill(['email' => null])->save();
        self::assertSame(Delivery::TurnedAway, $payments->remind($payment->refresh()));
        self::assertCount(3, Notification::query()->where(['user_id' => $this->ali->id, 'type' => NoticeType::PaymentReminder->value])->get());
    }

    public function testAMailServerThatRefusesLosesTheEmailButNotTheNotice(): void
    {
        $logs = $this->logs();
        $this->mail()->failing('Connection refused');
        $sara = $this->webCustomer();

        $delivery = $this->service(PaymentActions::class)->remind($this->cardPayment($this->topUpOrder($sara, '50000.00'), $this->cardMethod()));

        self::assertSame(Delivery::Unreachable, $delivery);
        self::assertTrue($logs->hasWarningThatContains('did not go'), 'the Mailer says why');
        self::assertSame(NoticeType::PaymentReminder, $this->latest($sara)->type, 'kept for her website all the same');
    }

    public function testAnAgentsCustomerHearsFromTheAgentsBotAndTheNoticeIsTheAgentsShops(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(), $this->plan(['traffic_gb' => 10], $this->server), $this->server, 'reza_1'));

        $this->service(CustomerNotifier::class)->serviceEnabled($theirs);

        $notice = CurrentBot::run($bot, fn(): Notification => Notification::query()->sole());
        self::assertSame([$bot->id, $theirs->user_id, NoticeType::ServiceEnabled], [$notice->bot_id, $notice->user_id, $notice->type]);
        self::assertSame(0, Notification::query()->count(), "none in the main bot's shop");
    }

    public function testTheHousekeepingForgetsWhatIsHalfAYearOld(): void
    {
        $customer = $this->mirrored('ali_1');
        $notifier = $this->service(CustomerNotifier::class);
        Carbon::setTestNow('2026-03-01 12:00:00');
        $notifier->serviceEnabled($customer);
        Carbon::setTestNow('2026-05-01 12:00:00');
        $notifier->serviceEnabled($customer);
        $theirs = CurrentBot::run($this->agentBot(), function (): Subscription {
            Carbon::setTestNow('2026-03-01 12:00:00');

            return $this->subscription($this->customer(), $this->plan(['traffic_gb' => 10], $this->server), $this->server, 'reza_1');
        });
        $notifier->serviceEnabled($theirs);

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->service(PruneAccountsTask::class)->run();

        $left = CurrentBot::everywhere(static fn() => Notification::query()->pluck('created_at')->map(static fn(Carbon $at): string => $at->format('Y-m-d'))->all());
        self::assertSame(['2026-05-01'], $left, "every shop's notices older than " . Notices::KEEP_DAYS . ' days gone');
    }

    public function testSupportsAnswerToATicketAndItsClosingAreKeptWithTheTicket(): void
    {
        $tickets = $this->service(Tickets::class);
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');

        $tickets->answer($ticket, $this->panelActor(), ['body' => 'مشکل برطرف شد.'], null);
        $this->assertKept($this->ali, NoticeType::TicketAnswered, $ticket);
        self::assertSame(self::text(BotText::TicketAnswered, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال', 'answer' => 'مشکل برطرف شد.', 'picture' => '']), $this->latest($this->ali)->text);

        $tickets->close($ticket->refresh(), $this->panelActor());
        $this->assertKept($this->ali, NoticeType::TicketClosed, $ticket);
    }

    public function testACustomerWithoutTelegramHearsOfTheirTicketByEmailSupportsWordsAsTyped(): void
    {
        $mail = $this->mail();
        $sara = $this->webCustomer();
        $ticket = $this->ticket($sara, 'قطعی اتصال');

        $this->service(Tickets::class)->answer($ticket, $this->panelActor(), ['body' => "سلام\n<b>مشکل</b> برطرف شد."], null);

        $email = $this->emailOf($mail, $sara);
        self::assertSame(NoticeType::TicketAnswered->subject(), $email->getSubject());
        self::assertStringContainsString('&lt;b&gt;مشکل&lt;/b&gt; برطرف شد.', (string) $email->getHtmlBody(), "support's words as they typed them, never markup");
        $this->assertKept($sara, NoticeType::TicketAnswered, $ticket);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testALongAnswerIsCutToWhatAMessageHolds(): void
    {
        $ticket = $this->ticket($this->ali);

        $this->service(Tickets::class)->answer($ticket, $this->panelActor(), ['body' => str_repeat('ب', Tickets::BODY_MAX)], null);

        $text = $this->latest($this->ali)->text;
        self::assertLessThanOrEqual(Limits::MESSAGE, TelegramHtml::visibleLength($text));
        self::assertStringEndsWith('ب…', $text, 'the whole is on their website');
        self::assertSame([$text], $this->telegram()->sentTo(self::TELEGRAM_ID));
    }

    /**
     * The customer's latest notice is `$type`, about `$subject`, in the very words the bot last sent them (when it did).
     */
    private function assertKept(User $customer, NoticeType $type, Order|Subscription|Ticket|null $subject): void
    {
        $notice = $this->latest($customer);
        self::assertSame([$type, match (true) {
            $subject instanceof Order => NoticeSubject::Order,
            $subject instanceof Subscription => NoticeSubject::Subscription,
            $subject instanceof Ticket => NoticeSubject::Ticket,
            default => null,
        }, $subject?->id], [$notice->type, $notice->subject_type, $notice->subject_id], $type->value);

        if ($customer->telegram_id !== null) {
            $said = $this->telegram()->sentTo((int) $customer->telegram_id);
            self::assertSame(end($said), $notice->text, "{$type->value}: the words the bot wrote them");
        }
    }

    private function latest(User $customer): Notification
    {
        return Notification::query()->where('user_id', $customer->id)->latest('id')->firstOrFail();
    }

    /** The one email that went to the customer's address. */
    private function emailOf(RecordingMailTransport $mail, User $customer): Email
    {
        $emails = $mail->to((string) $customer->email);
        self::assertCount(1, $emails);

        return $emails[0];
    }

    /** A service of Ali's whose client is on the fake panel as the row has it. */
    private function mirrored(string $name): Subscription
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, $name);
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    /** A service of Ali's within the automatic renewal's days, its switch on, mirrored on the panel. */
    private function due(string $name): Subscription
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, $name, ['auto_renew' => true, 'expires_at' => now()->addDay()]);
        FakeProvider::mirror($subscription);

        return $subscription;
    }
}
