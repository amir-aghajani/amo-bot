<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Enums\NoticeSubject;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Exceptions\ServiceChangedHandsException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\AutoRenewal;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\ServiceTransfers;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\WebText;
use App\Modules\Users\Enums\UserStatus;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerLookup;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Monolog\LogRecord;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «انتقال سرویس»: a service handed to another customer of its shop by their Telegram id — by the customer while the shop
 * lets them, by support always. The new owner, «تمدید خودکار» off and the stamps cleared, the report group's line, in
 * one transaction; the previous owner's unpaid orders of it cancelled; the new owner told (and the previous one when
 * support did it); the client on its panel relabelled, a panel failure logged. Every refusal under its field, and a
 * customer's lookups and transfers limited.
 */
final class ServiceTransfersTest extends DatabaseTestCase
{
    /** Sara's Telegram id: the recipient. */
    private const SARA = 7070;

    private Server $server;
    private Plan $plan;
    private User $ali;
    private User $sara;
    private Subscription $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        $this->telegram();
        $this->server = $this->sellingServer();
        $this->plan = $this->plan([], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => self::SARA, 'first_name' => 'Sara', 'username' => 'sara']);
        $this->service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', [
            'download_bytes' => 5 * Traffic::GIGABYTE,
            'auto_renew' => true,
            'renewal_notified_at' => now()->subDay(),
            'expiry_reminded_at' => now()->subHour(),
            'traffic_reminded_at' => now()->subHour(),
        ]));
    }

    public function testTheCustomerHandsTheirServiceOnAsItIsItsAutoRenewOffAndItsStampsCleared(): void
    {
        $this->transfersOn();
        $kept = ['server_id', 'remote_name', 'subscription_url', 'traffic_limit_bytes', 'download_bytes', 'ip_limit', 'duration_hours', 'plan_id', 'status', 'is_test'];
        $before = $this->service->refresh()->only($kept);
        $expires = $this->service->expires_at?->toDateTimeString();

        $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali));

        $row = $this->service->fresh() ?? self::fail('gone');
        self::assertSame($this->sara->id, $row->user_id);
        self::assertSame($this->sara->id, $this->service->user->id, 'the service handed in says its new owner');
        self::assertFalse($row->auto_renew, 'the new owner switches «تمدید خودکار» on themselves');
        self::assertSame([null, null, null], [$row->renewal_notified_at, $row->expiry_reminded_at, $row->traffic_reminded_at], 'the new owner has heard nothing yet');
        self::assertSame($before, $row->only($kept), 'its link, term and traffic as they were');
        self::assertSame($expires, $row->expires_at?->toDateTimeString());
    }

    public function testItsClientOnThePanelNamesTheNewOwnerItsTermsUnchanged(): void
    {
        $this->transfersOn();

        $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali));

        self::assertSame(['ali_1'], FakeProvider::updatedNames(), 'the one client, updated once');
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame([self::SARA, self::SARA . ' | sara'], [$spec->telegramId, $spec->comment]);
        self::assertSame([30 * Traffic::GIGABYTE, 1], [$spec->totalBytes, $spec->ipLimit], 'its quota and devices as the panel had them');
        self::assertSame($this->service->expires_at?->getTimestamp(), $spec->expiry->deadline()?->getTimestamp(), 'its deadline as the panel had it');
        self::assertSame([], FakeProvider::$enabled, 'a running client is not switched');
        self::assertSame([[], []], [FakeProvider::$created, FakeProvider::$deleted]);
    }

    public function testATermWaitingForTheFirstConnectionStaysWaiting(): void
    {
        $this->service->forceFill(['starts_at' => null, 'expires_at' => null])->save();
        FakeProvider::mirror($this->service);

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertNull($spec->expiry->deadline());
        self::assertSame(720 * 3600, $spec->expiry->pendingSeconds());
        self::assertNull($this->service->fresh()?->expires_at);
    }

    public function testAClientSupportSwitchedOffIsNeverSwitchedOnByItsRelabel(): void
    {
        $this->service->forceFill(['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()])->save();
        FakeProvider::mirror($this->service, enabled: false);

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        self::assertSame(['ali_1'], FakeProvider::updatedNames());
        self::assertFalse(FakeProvider::lastUpdate('ali_1')->enabled, 'updated switched off');
        self::assertSame([], FakeProvider::$enabled, 'no switch on the way');
        self::assertFalse(FakeProvider::$clients[$this->server->id]['ali_1']->enabled);
        $row = $this->service->fresh();
        self::assertSame([$this->sara->id, SubscriptionStatus::Disabled], [$row?->user_id, $row?->status], 'support\'s switch stays');
    }

    public function testAClientThePanelSwitchedOffOnceUsedUpStaysOff(): void
    {
        // 3x-ui switches a client off by itself once it is used up: the service ended, support switched nothing.
        $this->service->forceFill(['status' => SubscriptionStatus::Expired, 'download_bytes' => 30 * Traffic::GIGABYTE])->save();
        FakeProvider::mirror($this->service, enabled: false);

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        self::assertFalse(FakeProvider::lastUpdate('ali_1')->enabled);
        self::assertFalse(FakeProvider::$clients[$this->server->id]['ali_1']->enabled, 'renewed, it runs again; relabelled, it does not');
        self::assertSame(SubscriptionStatus::Expired, $this->service->fresh()?->status);
    }

    public function testAPanelInBackoffIsNotAskedAndTheTransferStands(): void
    {
        $logs = $this->logs();
        $this->service->server->forceFill(['last_error' => 'Connection timed out', 'last_checked_at' => now()])->save();

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        self::assertSame($this->sara->id, $this->service->fresh()?->user_id);
        self::assertSame([], FakeProvider::$updated, 'its panel never asked: nobody waits out its timeout');
        self::assertTrue($logs->hasWarningThatPasses(fn(LogRecord $record): bool => str_contains($record->message, 'does not name them yet') && $record->context['id'] === $this->service->id));
    }

    public function testAPanelThatFailsTheRelabelIsLoggedAndTheTransferStands(): void
    {
        $logs = $this->logs();
        $this->reportGroup();
        FakeProvider::$down = [$this->server->id];

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        self::assertSame($this->sara->id, $this->service->fresh()?->user_id);
        self::assertSame([], FakeProvider::$updated);
        self::assertTrue($logs->hasWarningThatPasses(fn(LogRecord $record): bool => str_contains($record->message, 'does not name them yet') && $record->context['id'] === $this->service->id));
        self::assertSame(1, ReportMessage::query()->count(), 'reported all the same');
        self::assertCount(1, $this->telegram()->sentTo(self::SARA), 'and the new owner told');
    }

    public function testAPanelThatRefusesTheUpdateIsLoggedToo(): void
    {
        $logs = $this->logs();
        FakeProvider::$refusing[$this->server->id] = ['updateClient'];

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        self::assertSame($this->sara->id, $this->service->fresh()?->user_id);
        self::assertTrue($logs->hasWarningThatPasses(static fn(LogRecord $record): bool => str_contains($record->message, 'does not name them yet')));
    }

    public function testTheNewOwnerIsToldWithTheWayToItAndTheCustomerWhoGaveItIsNot(): void
    {
        $this->transfersOn();

        $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali));

        $said = $this->telegram()->sentTo(self::SARA);
        $linkChange = self::text(BotText::ServiceReceivedLinkChange);
        self::assertSame([$this->service(ServiceCard::class)->text($this->service->fresh() ?? self::fail('gone'), BotText::ServiceReceived, ['link_change' => $linkChange])], $said, 'what it is and where it stands');
        self::assertStringStartsWith('🎁 سرویس <code>ali_1</code> به حساب شما منتقل شد', $said[0]);
        self::assertStringEndsWith($linkChange, $said[0], 'the previous owner keeps the link: «تغییر لینک» cuts them off');
        self::assertStringContainsString('«تغییر لینک»', $linkChange);
        self::assertSame([[['text' => self::text(BotText::ReminderOpenService), 'callback_data' => SubscriptionHandler::serviceCallback($this->service->id)]]], $this->telegram()->buttonsSentTo(self::SARA));
        self::assertSame([], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the customer who gave it was answered where they did it');

        $notice = Notification::query()->sole();
        self::assertSame([$this->sara->id, NoticeType::ServiceReceived, NoticeSubject::Subscription, $this->service->id], [$notice->user_id, $notice->type, $notice->subject_type, $notice->subject_id]);
    }

    public function testSupportsTransferTellsBothCustomers(): void
    {
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        self::assertSame([self::text(BotText::ServiceTransferredBySupport, ['client' => 'ali_1', 'cancelled' => ''])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        self::assertCount(1, $this->telegram()->sentTo(self::SARA));
        self::assertSame([
            [$this->ali->id, NoticeType::ServiceTransferred, null, null],
            [$this->sara->id, NoticeType::ServiceReceived, NoticeSubject::Subscription, $this->service->id],
        ], Notification::query()->oldest('id')->get()->map(static fn(Notification $notice): array => [$notice->user_id, $notice->type, $notice->subject_type, $notice->subject_id])->all(), 'the previous owner\'s is about their account: the service is not theirs to open');
    }

    public function testTheReportGroupHearsWhoGaveItToWhomInThePurchasesTopic(): void
    {
        $this->reportGroup();
        $this->transfersOn();
        $other = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2'));

        $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali));
        $this->transfers()->transfer($other, $this->sara, $this->panelActor());

        self::assertSame([
            implode("\n", [
                "🔁 <b>سرویس به مشتری دیگری منتقل شد</b> · اشتراک \u{200E}#{$this->service->id}",
                '👤 از: <a href="tg://user?id=5151">Ali</a> · ' . "\u{200E}@ali · <code>5151</code>",
                '👤 به: <a href="tg://user?id=7070">Sara</a> · ' . "\u{200E}@sara · <code>7070</code>",
                '🔖 سرویس: <code>ali_1</code>',
                '📍 لوکیشن: آلمان',
                '✋ به درخواست خود مشتری',
            ]),
            implode("\n", [
                "🔁 <b>سرویس به مشتری دیگری منتقل شد</b> · اشتراک \u{200E}#{$other->id}",
                '👤 از: <a href="tg://user?id=5151">Ali</a> · ' . "\u{200E}@ali · <code>5151</code>",
                '👤 به: <a href="tg://user?id=7070">Sara</a> · ' . "\u{200E}@sara · <code>7070</code>",
                '🔖 سرویس: <code>ali_2</code>',
                '📍 لوکیشن: آلمان',
                '🎧 توسط پشتیبانی',
            ]),
        ], ReportMessage::query()->where('topic', Topic::Purchases->value)->oldest('id')->pluck('text')->all());
        self::assertSame(2, ReportMessage::query()->count(), 'nothing else');
    }

    public function testTheNewOwnerAndTheReportStandOrFallTogether(): void
    {
        $this->reportGroup();

        try {
            $this->whileListening('eloquent.creating: ' . ReportMessage::class, static fn() => throw new \RuntimeException('the report failed'), fn() => $this->transfers()->transfer($this->service, $this->sara, $this->panelActor()));
            self::fail('the report\'s failure goes up');
        } catch (\RuntimeException $e) {
            self::assertSame('the report failed', $e->getMessage());
        }

        $row = $this->service->fresh();
        self::assertSame([$this->ali->id, true], [$row?->user_id, $row?->auto_renew], 'nothing changed hands');
        self::assertSame([[], []], [FakeProvider::$updated, $this->telegram()->sentTo(self::SARA)], 'nobody told, no panel touched');
    }

    public function testCustomersAreRefusedWhileTheShopDoesNotOfferItAndSupportIsNot(): void
    {
        self::assertFalse($this->transfers()->offered(), 'off by default');
        self::assertSame(['status' => [ServiceTransfers::OFF]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali))));
        self::assertSame(['status' => [ServiceTransfers::OFF]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, (string) self::SARA, Actor::customer($this->ali))));
        self::assertFalse($this->transfers()->offeredFor($this->service));

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        self::assertSame($this->sara->id, $this->service->fresh()?->user_id, 'support transfers whatever the switch says');

        $this->transfersOn();
        self::assertTrue($this->transfers()->offeredFor($this->service));
    }

    public function testAFreeTestOrAServiceGoneFromItsPanelStaysWhereItIs(): void
    {
        $test = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'test_ali_1', ['is_test' => true, 'plan_id' => null]));
        $gone = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['status' => SubscriptionStatus::Deleted]));
        $this->transfersOn();

        foreach ([Actor::customer($this->ali), $this->panelActor()] as $by) {
            self::assertSame(['status' => [ServiceTransfers::TEST]], $this->refusedWith(fn() => $this->transfers()->transfer($test, $this->sara, $by)));
            self::assertSame(['status' => [ServiceTransfers::GONE]], $this->refusedWith(fn() => $this->transfers()->transfer($gone, $this->sara, $by)));
        }
        self::assertFalse(ServiceTransfers::transferable($test));
        self::assertFalse(ServiceTransfers::transferable($gone));
        self::assertFalse($this->transfers()->offeredFor($test));
        self::assertSame([$this->ali->id, $this->ali->id], [$test->fresh()?->user_id, $gone->fresh()?->user_id]);
    }

    public function testACustomerWaitsForAnOpenOrderOfTheServiceAndSupportDoesNot(): void
    {
        $this->transfersOn();
        $renewal = $this->service(OrderService::class)->createRenewal($this->ali, $this->service, $this->plan);

        self::assertSame(['status' => [ServiceTransfers::OPEN_ORDER]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali))));
        self::assertSame(['status' => [ServiceTransfers::OPEN_ORDER]], $this->refusedWith(fn() => $this->transfers()->check($this->service, Actor::customer($this->ali))));

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        self::assertSame($this->sara->id, $this->service->fresh()?->user_id);
        self::assertSame($this->ali->id, $renewal->refresh()->user_id, 'the order stays its buyer\'s');
    }

    /** A failed delivery ends by support alone (its retry or its refund): the customer is sent there, not told to wait. */
    public function testAFailedOrderOfTheServiceSendsTheCustomerToSupport(): void
    {
        $this->transfersOn();
        $orders = $this->service(OrderService::class);
        $failed = $orders->createRenewal($this->ali, $this->service, $this->plan);
        $failed->forceFill(['status' => OrderStatus::Failed, 'notes' => 'the panel did not answer'])->save();
        $orders->openExtra($this->ali, $this->service, ExtraKind::Traffic, 10, '20000.00');

        self::assertSame(['status' => [ServiceTransfers::FAILED_ORDER]], $this->refusedWith(fn() => $this->transfers()->check($this->service, Actor::customer($this->ali))), 'the failed one named, whatever else is open');
        self::assertSame(['status' => [ServiceTransfers::FAILED_ORDER]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->ali))));

        // Support gave the money back; the unpaid extra still holds it, as any open order does.
        $failed->forceFill(['status' => OrderStatus::Refunded])->save();
        self::assertSame(['status' => [ServiceTransfers::OPEN_ORDER]], $this->refusedWith(fn() => $this->transfers()->check($this->service, Actor::customer($this->ali))));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    public function testAnOrderThePreviousOwnerLeftOpenDoesNotHoldTheNewOne(): void
    {
        $this->transfersOn();
        // Its receipt in review, Ali's renewal outlives the transfer.
        $renewal = $this->service(OrderService::class)->createRenewal($this->ali, $this->service, $this->plan);
        $this->receipt($this->cardPayment($renewal, $this->cardMethod()));
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        self::assertSame(OrderStatus::Pending, $renewal->refresh()->status);
        $reza = $this->customer(['telegram_id' => 7272, 'username' => 'reza']);

        $this->transfers()->transfer($this->service, $reza, Actor::customer($this->sara));
        self::assertSame($reza->id, $this->service->fresh()?->user_id, 'Ali\'s order is not Sara\'s to finish');

        $this->service(OrderService::class)->createRenewal($reza, $this->service, $this->plan);
        self::assertSame(['status' => [ServiceTransfers::OPEN_ORDER]], $this->refusedWith(fn() => $this->transfers()->check($this->service, Actor::customer($reza))), 'his own is');
    }

    public function testAScreenDrawnBeforeTheTransferOrdersNothingForTheServiceAnyMore(): void
    {
        $orders = $this->service(OrderService::class);
        // What Ali's screen read before support handed the service to Sara.
        $seen = $this->service->fresh() ?? self::fail('gone');
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        foreach ([
            'a renewal' => fn() => $orders->openRenewal($this->ali, $seen, $this->plan, RenewalMethod::Extend),
            'an extra' => fn() => $orders->openExtra($this->ali, $seen, ExtraKind::Traffic, 10, '20000.00'),
        ] as $case => $open) {
            self::assertSame(['status' => [ServiceTransfers::MOVED_ON]], $this->refusedWith($open), $case);
        }
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
        self::assertSame($this->sara->id, $orders->openExtra($this->sara, $this->service, ExtraKind::Traffic, 10, '20000.00')->user_id, 'its new owner orders');
    }

    public function testAServiceGoneSinceTheScreenWasDrawnIsSaidToBeGoneNotHandedOver(): void
    {
        $orders = $this->service(OrderService::class);
        $seen = $this->service->fresh() ?? self::fail('gone');
        Subscription::query()->whereKey($this->service->id)->delete();

        foreach ([
            'a renewal' => fn() => $orders->openRenewal($this->ali, $seen, $this->plan, RenewalMethod::Extend),
            'an extra' => fn() => $orders->openExtra($this->ali, $seen, ExtraKind::Traffic, 10, '20000.00'),
        ] as $case => $open) {
            self::assertSame(['status' => [OrderService::SERVICE_GONE]], $this->refusedWith($open), $case);
        }
        self::assertSame(0, Order::query()->count(), 'nothing ordered');
    }

    public function testAnOrderTheGiverLeftOpenIsNeitherPaidNorDeliveredOnceTheServiceIsAnothers(): void
    {
        $orders = $this->service(OrderService::class);
        $card = $this->cardMethod();
        // Their receipts in review, both outlive the transfer.
        $extra = $orders->openExtra($this->ali, $this->service, ExtraKind::Traffic, 10, '20000.00');
        $sent = $this->receipt($this->cardPayment($extra, $card));
        $renewal = $orders->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend);
        $this->receipt($this->cardPayment($renewal, $card), messageId: self::RECEIPT_MESSAGE + 1);
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        $limit = $this->service->fresh()?->traffic_limit_bytes;

        $this->wallet($this->ali, '500000');
        try {
            $this->service(PaymentService::class)->createForOrder($renewal, $this->walletMethod());
            self::fail('paid for a service no longer his');
        } catch (OrderNotPayableException) {
            self::assertSame(['500000.00', OrderStatus::Pending], [$this->ali->balance(), $renewal->refresh()->status], 'nothing charged');
        }

        // The receipt sent before the transfer is approved after it: the money came, the service is Sara's.
        $this->service(PaymentService::class)->approve($sent, 'admin');
        self::assertSame([OrderStatus::Failed, ServiceChangedHandsException::MESSAGE], [$extra->refresh()->status, $extra->notes], 'support gives the money back');
        self::assertSame($limit, $this->service->fresh()?->traffic_limit_bytes, "nothing added to Sara's service");
    }

    public function testTheGiverIsToldTheServiceIsNoLongerTheirsAndSupportSettlesThePaymentWithThem(): void
    {
        $orders = $this->service(OrderService::class);
        $card = $this->cardMethod();
        // Ali's renewal and a website customer's extra — she has no Telegram — each with its receipt in review when support
        // hands their services on.
        $renewal = $orders->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend);
        $sent = $this->receipt($this->cardPayment($renewal, $card));
        $web = $this->webCustomer();
        $hers = $this->mirrored($this->subscription($web, $this->plan, $this->server, 'web_1'));
        $extra = $orders->openExtra($web, $hers, ExtraKind::Traffic, 10, '20000.00');
        $uploaded = $this->receipt($this->cardPayment($extra, $card), messageId: self::RECEIPT_MESSAGE + 1);
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        $this->transfers()->transfer($hers, $this->sara, $this->panelActor());
        $this->telegram()->reset();
        $mail = $this->mail();

        $this->service(PaymentActions::class)->approve($sent, $this->panelActor());
        $this->service(PaymentActions::class)->approve($uploaded, $this->panelActor());

        foreach ([$renewal, $extra] as $order) {
            self::assertSame([OrderStatus::Failed, ServiceChangedHandsException::MESSAGE], [$order->refresh()->status, $order->notes], "support's note: give the money back");
        }
        $toAli = self::text(BotText::PayServiceChangedHands, ['order' => $renewal->id, 'client' => 'ali_1']);
        self::assertSame([$toAli], $this->telegram()->sentTo(self::TELEGRAM_ID), 'his service no longer his, support settles with him — never that support will renew it');
        $toHer = self::text(BotText::PayServiceChangedHands, ['order' => $extra->id, 'client' => 'web_1']);
        $told = static fn(User $customer): string => Notification::query()->where('user_id', $customer->id)->where('type', NoticeType::PaymentSettled->value)->sole()->text;
        self::assertSame([$toAli, $toHer], [$told($this->ali), $told($web)], "the same words in their website's feed");
        $email = $mail->to(self::WEB_EMAIL)[0] ?? self::fail('No email went to her.');
        self::assertStringContainsString(WebText::html($toHer), (string) $email->getHtmlBody(), 'and by email to her, who has no Telegram');
    }

    public function testADeliveryUnderWayWhenSupportHandsTheServiceOnAddsNothingToIt(): void
    {
        $orders = $this->service(OrderService::class);
        $provisioning = $this->service(ProvisioningService::class);
        // Both read the service as Ali's when their delivery was claimed; support's transfer does not wait for them.
        $renewal = $orders->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend)->load('subscription');
        $extra = $orders->openExtra($this->ali, $this->service, ExtraKind::Traffic, 10, '20000.00')->load('subscription');
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        $before = [count(FakeProvider::$updated), $this->service->fresh()?->traffic_limit_bytes, $this->service->fresh()?->expires_at?->toIso8601String()];

        foreach (['the renewal' => static fn() => $provisioning->renew($renewal, static fn() => self::fail('delivered')), 'the extra' => static fn() => $provisioning->addExtra($extra, static fn() => self::fail('delivered'))] as $what => $deliver) {
            try {
                $deliver();
                self::fail("{$what} was added to a service no longer Ali's");
            } catch (ServiceChangedHandsException) {
                // Read again under the service's hold: Sara's now.
            }
        }
        self::assertSame($before, [count(FakeProvider::$updated), $this->service->fresh()?->traffic_limit_bytes, $this->service->fresh()?->expires_at?->toIso8601String()], "nothing sent to the panel, Sara's service as she got it");
    }

    public function testATransferWaitsForAChangeUnderWayOnThePanelWhichLandsOnTheGiversService(): void
    {
        $this->wallet($this->ali, '500000');
        $renewal = $this->service(OrderService::class)->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend);
        $refusals = [];
        // Support hands the service on while Ali's renewal is being sent to its panel.
        FakeProvider::$onCall = function (int $server, string $call) use (&$refusals): void {
            if ($call === 'updateClient' && $refusals === []) {
                $refusals[] = $this->refusedWith(fn() => $this->transfers()->transfer($this->service->fresh() ?? self::fail('gone'), $this->sara, $this->panelActor()));
            }
        };

        $this->service(PaymentService::class)->createForOrder($renewal, $this->walletMethod());

        self::assertSame([['status' => [ServiceTransfers::BUSY]]], $refusals, 'refused: try again in a moment');
        self::assertSame([OrderStatus::Fulfilled, $this->ali->id], [$renewal->refresh()->status, $this->service->fresh()?->user_id], "renewed — Ali's, who paid");

        FakeProvider::$onCall = null;
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        self::assertSame($this->sara->id, $this->service->fresh()?->user_id, 'once it is through');
        self::assertNull($this->service->refresh()->getAttribute('lease_token'), 'let go');
    }

    public function testSupportsTransferCancelsWhatTheGiverLeftUnpaidAndLeavesAReceiptInReview(): void
    {
        $orders = $this->service(OrderService::class);
        $card = $this->cardMethod();
        // A renewal shown the card's number, nothing sent yet; extra traffic not paid at all; extra days whose receipt waits.
        $renewal = $orders->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend);
        $started = $this->cardPayment($renewal, $card);
        $traffic = $orders->openExtra($this->ali, $this->service, ExtraKind::Traffic, 10, '20000.00');
        $days = $orders->openExtra($this->ali, $this->service, ExtraKind::Time, 7, '14000.00');
        $sent = $this->receipt($this->cardPayment($days, $card));
        $other = $orders->openRenewal($this->ali, $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2')), $this->plan, RenewalMethod::Extend);

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        foreach ([$renewal, $traffic] as $order) {
            self::assertSame([OrderStatus::Cancelled, ServiceTransfers::NOTE_CANCELLED], [$order->refresh()->status, $order->notes]);
        }
        self::assertSame([PaymentStatus::Cancelled, ServiceTransfers::NOTE_CANCELLED], [$started->refresh()->status, $started->note]);
        self::assertSame([OrderStatus::Pending, PaymentStatus::AwaitingReview], [$days->refresh()->status, $sent->refresh()->status], 'the receipt may be the money: it fails at its delivery for support to give it back');
        self::assertSame(OrderStatus::Pending, $other->refresh()->status, "his other service's is his still");
        self::assertSame([self::text(BotText::ServiceTransferredBySupport, [
            'client' => 'ali_1',
            'cancelled' => self::text(BotText::ServiceTransferredOrders, ['orders' => "\u{200E}#{$renewal->id}، \u{200E}#{$traffic->id}"]),
        ])], $this->telegram()->sentTo(self::TELEGRAM_ID), 'he hears which went with it, each number marked to read left to right');
    }

    public function testTheNewOwnersAutomaticRenewalGoesAheadOnceTheGiversUnpaidRenewalIsCancelled(): void
    {
        $this->service->forceFill(['expires_at' => now()->addDay()])->save();
        $this->mirrored($this->service);
        $this->cardPayment($this->service(OrderService::class)->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend), $this->cardMethod());

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        $this->service(AutoRenewal::class)->set($this->service, true);
        $this->wallet($this->sara, '500000');
        $this->service(AutoRenewTask::class)->run();

        $renewed = Order::query()->where('user_id', $this->sara->id)->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Fulfilled, $this->service->id], [$renewed->type, $renewed->status, $renewed->subscription_id]);
    }

    public function testARenewalTheGiverLeftFailedHoldsNoneOfTheNewOwners(): void
    {
        $this->service->forceFill(['expires_at' => now()->addDay()])->save();
        $this->mirrored($this->service);
        // Ali's receipt is approved after support gave the service to Sara: it fails, for support to give the money back.
        $orders = $this->service(OrderService::class);
        $left = $orders->openRenewal($this->ali, $this->service, $this->plan, RenewalMethod::Extend);
        $this->receipt($this->cardPayment($left, $this->cardMethod()));
        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());
        $left->forceFill(['status' => OrderStatus::Failed, 'notes' => ServiceChangedHandsException::MESSAGE])->save();

        self::assertFalse($orders->renewalUnderWay($this->service->refresh()), "Ali's failed renewal is not Sara's");
        $this->service(AutoRenewal::class)->set($this->service, true);
        $this->wallet($this->sara, '500000');
        $this->service(AutoRenewTask::class)->run();

        $renewed = Order::query()->where('user_id', $this->sara->id)->sole();
        self::assertSame([OrderType::Renewal, OrderStatus::Fulfilled], [$renewed->type, $renewed->status], 'her automatic renewal goes ahead');
        self::assertSame(OrderStatus::Failed, $left->refresh()->status, "Ali's waits for support");
    }

    public function testThePreviousOwnerKeepingTheLinkIsNotSaidWhereItsPanelCannotChangeIt(): void
    {
        FakeProvider::$capabilities = new Capabilities(inbounds: true, ipLimit: true);

        $this->transfers()->transfer($this->service, $this->sara, $this->panelActor());

        $said = $this->telegram()->sentTo(self::SARA);
        self::assertSame([$this->service(ServiceCard::class)->text($this->service->fresh() ?? self::fail('gone'), BotText::ServiceReceived, ['link_change' => ''])], $said);
        self::assertStringNotContainsString('«تغییر لینک»', $said[0]);
    }

    public function testTheRecipientIsTheShopsCustomerByTheirTelegramId(): void
    {
        $this->transfersOn();

        foreach ([self::SARA, (string) self::SARA, ' ۷۰۷۰ '] as $typed) {
            self::assertSame($this->sara->id, $this->transfers()->recipient($this->service, $typed, Actor::customer($this->ali))->id);
        }
        foreach (['', 'sara', '@sara', '70 70', '-7070', '0', '7070.5', null, ['7070']] as $typed) {
            self::assertSame(['telegram_id' => [CustomerLookup::NOT_AN_ID]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, $typed, Actor::customer($this->ali))), (string) json_encode($typed));
        }
        self::assertSame(['telegram_id' => [CustomerLookup::UNKNOWN]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, '9999', Actor::customer($this->ali))), 'nobody of that id');

        $sara = $this->transfers()->transferById($this->service, ' ۷۰۷۰', $this->panelActor());
        self::assertSame([$this->sara->id, $this->sara->id], [$sara->id, $this->service->fresh()?->user_id]);
    }

    public function testACustomerOfAnotherShopIsNoRecipient(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): User => $this->customer(['telegram_id' => 8080, 'username' => 'reza']));

        self::assertSame(['telegram_id' => [CustomerLookup::UNKNOWN]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, '8080', $this->panelActor())));
        self::assertSame(['telegram_id' => [CustomerLookup::UNKNOWN]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $theirs, $this->panelActor())));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    public function testTheOwnerABannedAccountAndTheActingAdminAreNoRecipients(): void
    {
        $this->transfersOn();
        $banned = $this->customer(['telegram_id' => 7171, 'status' => UserStatus::Banned]);

        self::assertSame(['telegram_id' => [ServiceTransfers::SAME]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, (string) self::TELEGRAM_ID, Actor::customer($this->ali))));
        self::assertSame(['telegram_id' => [ServiceTransfers::SAME]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $this->ali, $this->panelActor())));
        self::assertSame(['telegram_id' => [ServiceTransfers::BANNED]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, '7171', Actor::customer($this->ali))));
        self::assertSame(['telegram_id' => [ServiceTransfers::BANNED]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $banned, $this->panelActor())));

        $boss = $this->groupAdminActor();
        self::assertSame(['telegram_id' => [ServiceTransfers::TO_THEMSELVES]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, (string) self::BOSS_TELEGRAM_ID, $boss)));
        self::assertSame($this->ali->id, $this->service->fresh()?->user_id);
    }

    public function testAShopAdminDoesNotHandOnTheirOwnServiceAndACustomerOnlyTheirs(): void
    {
        $this->transfersOn();
        $boss = $this->groupAdminActor();
        $theirs = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'boss_1', ['user_id' => $boss->userId]));

        try {
            $this->transfers()->transfer($theirs, $this->sara, $boss);
            self::fail('a shop admin\'s own service');
        } catch (ActorRefusedException $e) {
            self::assertSame([403, ActorRefusedException::OWN_SERVICE], [$e->status(), $e->getMessage()]);
        }
        self::assertSame(['status' => [ServiceTransfers::MOVED_ON]], $this->refusedWith(fn() => $this->transfers()->transfer($this->service, $this->sara, Actor::customer($this->sara))), 'not hers to give');
        self::assertSame([$boss->userId, $this->ali->id], [$theirs->fresh()?->user_id, $this->service->fresh()?->user_id]);
    }

    public function testAServiceGivenAwayMeanwhileIsNotGivenAgain(): void
    {
        $this->reportGroup();
        $read = $this->service->fresh() ?? self::fail('gone');
        $reza = $this->customer(['telegram_id' => 7272, 'username' => 'reza']);
        $this->transfers()->transfer($this->service, $reza, $this->panelActor());

        self::assertSame(['status' => [ServiceTransfers::MOVED_ON]], $this->refusedWith(fn() => $this->transfers()->transfer($read, $this->sara, $this->panelActor())));
        self::assertSame($reza->id, $this->service->fresh()?->user_id);
        self::assertSame(1, ReportMessage::query()->count(), 'one transfer reported');
    }

    public function testACustomerLooksUpTenRecipientsInTenMinutesAndSupportIsNotCounted(): void
    {
        $this->transfersOn();
        for ($i = 0; $i < CustomerLookup::LOOKUPS; $i++) {
            $this->transfers()->recipient($this->service, (string) self::SARA, Actor::customer($this->ali));
        }
        self::assertSame(['telegram_id' => [CustomerLookup::NOT_AN_ID]], $this->refusedWith(fn() => $this->transfers()->recipient($this->service, 'sara', Actor::customer($this->ali))), 'what is no id is no lookup');

        try {
            $this->transfers()->recipient($this->service, (string) self::SARA, Actor::customer($this->ali));
            self::fail('one too many');
        } catch (TooManyAttemptsException $e) {
            self::assertSame([429, CustomerLookup::LOOKUP_WINDOW], [$e->status(), $e->retryAfter]);
        }

        for ($i = 0; $i <= CustomerLookup::LOOKUPS; $i++) {
            $this->transfers()->recipient($this->service, (string) self::SARA, $this->panelActor());
        }
        Carbon::setTestNow(now()->addSeconds(CustomerLookup::LOOKUP_WINDOW + 1));
        self::assertSame($this->sara->id, $this->transfers()->recipient($this->service, (string) self::SARA, Actor::customer($this->ali))->id, 'a new window');
    }

    public function testACustomerHandsOnFiveServicesAnHourAndSupportIsNotCounted(): void
    {
        $this->transfersOn();
        $services = [$this->service];
        for ($i = 2; $i <= ServiceTransfers::TRANSFERS + 2; $i++) {
            $services[] = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, "ali_{$i}"));
        }

        foreach (array_slice($services, 0, ServiceTransfers::TRANSFERS) as $service) {
            $this->transfers()->transfer($service, $this->sara, Actor::customer($this->ali));
        }
        $next = $services[ServiceTransfers::TRANSFERS];
        try {
            $this->transfers()->transfer($next, $this->sara, Actor::customer($this->ali));
            self::fail('one too many');
        } catch (TooManyAttemptsException $e) {
            self::assertSame(ServiceTransfers::TRANSFER_WINDOW, $e->retryAfter);
        }
        self::assertSame($this->ali->id, $next->fresh()?->user_id);

        $this->transfers()->transfer($next, $this->sara, $this->panelActor());
        self::assertSame($this->sara->id, $next->fresh()?->user_id, 'support is not counted');
    }

    private function transfers(): ServiceTransfers
    {
        return $this->service(ServiceTransfers::class);
    }
}
