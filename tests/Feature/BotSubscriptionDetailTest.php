<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\Capabilities;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\Ticket;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A service opened from "سرویس‌های من": what the panel says about it right now — or, the panel out of reach or left
 * alone a while, the row's last numbers, flagged —, its buttons, «لینک اشتراک» — the kept link in place of the screen,
 * with a way back —, «تغییر لینک» — a new link on the panel once confirmed, sent like a delivery — and «ارسال گزارش
 * اختلال» — a support ticket about it.
 */
final class BotSubscriptionDetailTest extends BotTestCase
{
    /** The link the panel served for ali_1 when it was made (the fixture's), rotated below. */
    private const LINK = 'https://fake.test/sub/ali_1';

    /** When the customer last connected, as the panel says (setUp). */
    private const LAST_ONLINE = '2026-09-19 09:30:00';

    private User $user;
    private Server $server;
    private Plan $plan;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-19 12:00:00');
        $this->fakePanel();
        $this->withoutQr();
        $this->inlineStartMenu();

        $this->user = $this->customer(['username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
        $this->subscription = $this->subscription($this->user, $this->plan, $this->server, 'ali_1');

        FakeProvider::put($this->server, new ClientInfo(
            name: 'ali_1',
            enabled: true,
            uploadBytes: 512 * Traffic::MEGABYTE,
            downloadBytes: Traffic::GIGABYTE,
            totalBytes: 30 * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-10-19 12:00:00')),
            subscriptionUrl: self::LINK,
            lastOnlineAt: new \DateTimeImmutable(self::LAST_ONLINE),
        ));
    }

    public function testTheScreenShowsTheServiceAsThePanelSeesIt(): void
    {
        FakeProvider::$online = ['ali_1'];

        $this->send($this->tap($this->screenButton()));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the screen, then the tap acknowledged');
        self::assertSame($this->screen($this->asThePanelSays(online: true)), $this->params(0)['text'], 'the panel\'s counters and presence, and the hint at «تغییر لینک»');

        $keyboard = $this->inlineKeyboard(0);
        $id = $this->subscription->id;
        self::assertSame([['text' => self::text(BotText::ServiceRefresh), 'callback_data' => "sub:{$id}:refresh", 'style' => 'primary']], $keyboard[0], 'the wire format of a service button');
        self::assertSame([self::text(BotText::ServiceLink), self::text(BotText::ServiceRenew)], array_column($keyboard[1], 'text'), 'renew on the right, link on the left');
        self::assertSame(['success', 'primary'], [$keyboard[1][1]['style'], $keyboard[1][0]['style']]);
        self::assertSame([['text' => self::text(BotText::ServiceAutoRenewOff), 'callback_data' => $this->screenButton('autorenew')]], $keyboard[2], 'off, as the shop starts new services');
        self::assertSame([self::text(BotText::ServiceRotate), self::text(BotText::ServiceReport)], array_column($keyboard[3], 'text'));
        self::assertSame($this->screenButton('rotate'), $keyboard[3][0]['callback_data']);
        self::assertSame(['text' => self::text(BotText::ServiceBack), 'callback_data' => MenuHandler::subscriptionsCallback(1), 'style' => 'danger'], $keyboard[4][0]);

        // The row learned the counters on the way.
        self::assertSame(Traffic::GIGABYTE, $this->subscription->refresh()->download_bytes);
    }

    public function testAPanelThatCannotRotateCredentialsGetsNoChangeLinkButton(): void
    {
        FakeProvider::$capabilities = new Capabilities(linkRotation: false);

        $this->send($this->tap($this->screenButton()));

        $keyboard = $this->inlineKeyboard(0);
        self::assertSame([self::text(BotText::ServiceReport)], array_column($keyboard[3], 'text'), 'the report button stands alone');
        self::assertNotContains($this->screenButton('rotate'), $this->callbacks(0));
        self::assertCount(5, $keyboard);
        self::assertSame($this->screen($this->asThePanelSays(online: false), rotates: false), $this->params(0)['text'], 'nor the hint that points at it');
    }

    public function testOfflineNeverConnectedAndUnlimitedRead(): void
    {
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: 0, subscriptionUrl: self::LINK));

        $this->send($this->tap($this->screenButton()));

        self::assertSame($this->screen([
            'traffic' => Messages::traffic(0),
            'used' => self::text(BotText::ServiceUnused),
            'remaining' => Messages::UNLIMITED,
            'expires' => Messages::expiry(null, 0),
            'last_online' => self::text(BotText::ServiceNeverConnected),
            'connection' => self::text(BotText::ServiceOffline),
        ]), $this->params(0)['text']);
    }

    public function testUntilTheFirstConnectionThereIsNoEndDate(): void
    {
        // The panel keeps the clock and has not started it: the term rides on the client as "expires after".
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::afterFirstUse(30 * 86400), subscriptionUrl: self::LINK));

        $this->send($this->tap($this->screenButton()));

        self::assertSame($this->screen($this->unused(Messages::expiry(null, 30), self::text(BotText::ServiceNeverConnected))), $this->params(0)['text'], 'no end date: waiting for the first connection');
        $row = $this->subscription->refresh();
        self::assertNull($row->expires_at, 'the row follows the panel: no deadline yet');
        self::assertSame(30, $row->duration_days);
        self::assertTrue($row->awaitsFirstUse());

        // The customer connected: the panel turned the term into a deadline, and the row learns it.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(new \DateTimeImmutable('2026-10-21 08:00:00')), subscriptionUrl: self::LINK, lastOnlineAt: new \DateTimeImmutable('2026-09-21 08:00:00')));
        $this->send($this->tap($this->screenButton('refresh')));

        $connected = $this->unused(Messages::expiry(new \DateTimeImmutable('2026-10-21 08:00:00'), 30), Persian::date(new \DateTimeImmutable('2026-09-21 08:00:00'), withTime: true));
        self::assertSame($this->screen($connected), $this->params(1)['text'], 'after the toast');
        $row = $this->subscription->refresh();
        self::assertSame('2026-10-21 08:00:00', $row->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-21 08:00:00', $row->starts_at?->format('Y-m-d H:i:s'), 'started a term before the deadline');
    }

    public function testADepletedServiceSaysSoAndLeavesTheList(): void
    {
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: false, downloadBytes: 31 * Traffic::GIGABYTE, totalBytes: 30 * Traffic::GIGABYTE, expiry: Expiry::at(new \DateTimeImmutable('2026-10-19 12:00:00')), subscriptionUrl: self::LINK));

        $this->send($this->tap($this->screenButton()));

        self::assertSame($this->screen([
            'status' => self::text(BotText::ServiceStatusDepleted),
            'traffic' => Messages::traffic(30 * Traffic::GIGABYTE),
            'used' => Messages::bytes(31 * Traffic::GIGABYTE),
            'remaining' => Messages::bytes(0) . ' (' . Messages::percent(0, 30 * Traffic::GIGABYTE) . ')',
            'expires' => Messages::expiry(new \DateTimeImmutable('2026-10-19 12:00:00'), 30),
            'last_online' => self::text(BotText::ServiceNeverConnected),
            'connection' => self::text(BotText::ServiceOffline),
        ]), $this->params(0)['text']);
        self::assertSame(SubscriptionStatus::Expired, $this->subscription->refresh()->status, 'and it leaves the list of active services');
    }

    public function testWhenThePanelIsDownTheLastKnownNumbersAreShownAndFlagged(): void
    {
        $this->subscription->forceFill(['download_bytes' => 2 * Traffic::GIGABYTE])->save(); // what the last sync read
        FakeProvider::$unreachable = true;

        $this->send($this->tap($this->screenButton()));

        self::assertSame($this->screen($this->asTheRowSays(), stale: true), $this->params(0)['text']);
        self::assertContains($this->screenButton('rotate'), $this->callbacks(0), 'the buttons stay');
    }

    public function testAPanelTheShopLeavesAloneIsNotWaitedOnForEveryCustomer(): void
    {
        $this->subscription->forceFill(['download_bytes' => 2 * Traffic::GIGABYTE])->save(); // what the last sync read
        // The sync found the panel out of reach: the shop leaves it alone a while (Server::BACKOFF_MINUTES).
        FakeProvider::$down = [$this->server->id];
        $this->service(SyncSubscriptionsTask::class)->run();
        FakeProvider::$down = [];

        $this->send($this->tap($this->screenButton()));
        self::assertSame($this->screen($this->asTheRowSays(), stale: true), $this->params(0)['text'], 'the row\'s numbers: the panel was not asked');

        $this->send($this->tap($this->screenButton('refresh')));
        self::assertSame(['answerCallbackQuery'], $this->calls(), 'a refresh promises the panel\'s numbers: it says it could not');
        self::assertSame(self::text(BotText::ServiceRefreshFailed), $this->popup());

        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->send($this->tap($this->screenButton()));
        self::assertSame($this->screen($this->asThePanelSays(online: false)), $this->params(0)['text'], 'asked again once the while is up');
    }

    public function testRefreshWithoutThePanelSaysSoAndKeepsTheScreen(): void
    {
        FakeProvider::$unreachable = true;

        $this->send($this->tap($this->screenButton('refresh')));

        self::assertSame(['answerCallbackQuery'], $this->calls(), 'the screen is not redrawn from the row\'s copy');
        self::assertSame([self::text(BotText::ServiceRefreshFailed), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);
    }

    public function testRefreshAnswersWithAToastAndBackGoesToTheServicesPage(): void
    {
        // Six newer services push this one onto page 2 of the list.
        foreach (range(2, 7) as $n) {
            $this->subscription($this->user, $this->plan, $this->server, "ali_{$n}");
        }

        $this->send($this->tap($this->screenButton('refresh')));

        self::assertSame(['answerCallbackQuery', 'editMessageText'], $this->calls());
        self::assertSame(self::text(BotText::ServiceRefreshed), $this->popup());
        $keyboard = $this->inlineKeyboard(1);
        self::assertSame(MenuHandler::subscriptionsCallback(2), end($keyboard)[0]['callback_data']);
    }

    public function testSomeoneElsesServiceIsNotFound(): void
    {
        $other = $this->customer(['telegram_id' => 9999, 'first_name' => 'Bob']);
        $theirs = $this->subscription($other, $this->plan, $this->server, 'bob_1');

        $this->send($this->tap(SubscriptionHandler::serviceCallback($theirs->id)));

        self::assertSame(self::text(BotText::ServiceNotFound), $this->params(0)['text']);
        self::assertSame(MenuHandler::subscriptionsCallback(1), $this->inlineKeyboard(0)[0][0]['callback_data']);
    }

    public function testAServiceGoneFromItsPanelIsNotFoundEither(): void
    {
        $gone = $this->subscription($this->user, $this->plan, $this->server, 'ali_2', ['status' => SubscriptionStatus::Deleted]);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($gone->id)));

        self::assertSame(self::text(BotText::ServiceNotFound), $this->params(0)['text']);
    }

    public function testAnOutageReportIsASupportTicketAboutTheService(): void
    {
        $this->send($this->tap($this->screenButton('report')));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'in place of the screen');
        self::assertSame(self::text(BotText::TicketReportAsk, ['client' => 'ali_1']), $this->params(0)['text']);
        self::assertSame([$this->screenButton()], $this->callbacks(0), 'and the way back to it');

        $this->send($this->message("از دیشب روی گوشی وصل نمی‌شود\nروی لپ‌تاپ هم همین‌طور."));

        $ticket = Ticket::query()->sole();
        self::assertSame([$this->subscription->id, 'از دیشب روی گوشی وصل نمی‌شود', TicketChannel::Bot], [$ticket->subscription_id, $ticket->subject, $ticket->messages()->sole()->channel]);
        self::assertSame([self::text(BotText::TicketOpened, ['ticket' => $ticket->id, 'subject' => $ticket->subject])], $this->said());
    }

    public function testBackFromAnOutageReportLeavesItUnwritten(): void
    {
        $this->send($this->tap($this->screenButton('report')));
        $this->send($this->tap($this->screenButton()));

        $this->send($this->message('سلام'));

        self::assertSame(0, Ticket::query()->count(), 'the chat is back on the service: what it says next is no report');
        self::assertSame([self::text(BotText::Unknown)], $this->said());
    }

    public function testTheLinkButtonShowsTheKeptLinkInPlaceOfTheScreen(): void
    {
        // The link is the shop's copy: no panel round-trip, so it shows while the panel is down.
        FakeProvider::$unreachable = true;

        $this->send($this->tap($this->screenButton('link')));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the screen becomes the link — one message');
        self::assertSame('77', $this->params(0)['message_id']);
        self::assertSame(self::text(BotText::LinkRequested, [
            'client' => 'ali_1',
            'plan' => $this->plan->name,
            'server' => $this->server->name,
            'subscription' => self::LINK,
        ]), $this->params(0)['text'], 'which service, and its link in a span a tap copies');
        self::assertSame(
            [[['text' => self::text(BotText::Back), 'callback_data' => $this->screenButton()]]],
            $this->inlineKeyboard(0),
            'back to managing the service',
        );
    }

    public function testBackFromTheQrCardPutsTheScreenInItsPlace(): void
    {
        // "Back" on the QR card, a photo message: Telegram cannot edit a photo into text, so the card goes
        // and the screen comes — still one message.
        $this->send($this->tap($this->screenButton(), message: ['photo' => [['file_id' => 'qr-card', 'width' => 1024, 'height' => 1024]]]));

        self::assertSame(['deleteMessage', 'sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertSame('77', $this->params(0)['message_id']);
        self::assertSame($this->screen($this->asThePanelSays(online: false)), $this->params(1)['text']);
        self::assertContains($this->screenButton('link'), $this->callbacks(1), 'with its buttons');
    }

    public function testDrawingTheScreenLearnsALinkThePanelMoved(): void
    {
        // The panel's subscription server moved to another domain: the kept link follows it.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE, subscriptionUrl: 'https://sub.example.test/ali_1'));

        $this->send($this->tap($this->screenButton()));
        self::assertSame('https://sub.example.test/ali_1', $this->subscription->refresh()->subscription_url);

        $this->send($this->tap($this->screenButton('link')));
        self::assertStringContainsString('<code>https://sub.example.test/ali_1</code>', $this->params(0)['text']);
    }

    public function testAPanelThatStoppedServingLinksLeavesTheLastOneKnown(): void
    {
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, totalBytes: 30 * Traffic::GIGABYTE));

        $this->send($this->tap($this->screenButton('refresh')));

        self::assertSame(self::LINK, $this->subscription->refresh()->subscription_url);
    }

    public function testChangingTheLinkAsksFirst(): void
    {
        $this->send($this->tap($this->screenButton('rotate')));

        self::assertSame(self::text(BotText::ServiceRotateConfirm, ['client' => 'ali_1']), $this->params(0)['text'], 'every device on the old link drops');
        $row = $this->inlineKeyboard(0)[0];
        self::assertSame([self::text(BotText::Cancel), self::text(BotText::ServiceRotateYes)], array_column($row, 'text'), '"yes" on the right');
        self::assertSame(['text' => self::text(BotText::ServiceRotateYes), 'callback_data' => $this->screenButton('rotate', 'yes'), 'style' => 'danger'], $row[1]);
        self::assertSame($this->screenButton(), $row[0]['callback_data'], '"no" is the screen again');
        self::assertSame([], FakeProvider::$rotated, 'nothing changed yet');
    }

    public function testTheConfirmedChangeRotatesThePanelClientAndSendsTheNewLinkInPlaceOfTheConfirm(): void
    {
        $this->send($this->tap($this->screenButton('rotate')));
        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        self::assertSame(['ali_1'], FakeProvider::$rotated);
        $row = $this->subscription->refresh();
        self::assertSame('https://fake.test/sub/sub-rotated-1', $row->subscription_url, 'the row carries the new link');

        self::assertSame(['answerCallbackQuery', 'deleteMessage', 'sendMessage'], $this->calls(), 'the confirm goes, the link comes — nothing else');
        self::assertSame(self::text(BotText::ServiceRotated), $this->popup());
        $link = $this->params(2);
        self::assertSame(self::text(BotText::LinkRotated, [
            'client' => 'ali_1',
            'plan' => $this->plan->name,
            'server' => $this->server->name,
            'expires' => Messages::expiry(new \DateTimeImmutable('2026-10-19 12:00:00'), 30),
            'traffic' => Messages::traffic(30 * Traffic::GIGABYTE),
            'subscription' => 'https://fake.test/sub/sub-rotated-1',
        ]), $link['text'], 'the new link, like a delivery');
        self::assertArrayNotHasKey('reply_markup', $link);
    }

    public function testASecondTapOnTheConfirmationChangesNothing(): void
    {
        $this->send($this->tap($this->screenButton('rotate')));
        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        // The same button again — a double tap, or the confirm still on screen: the link just delivered must stand.
        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        self::assertSame(['ali_1'], FakeProvider::$rotated, 'rotated once');
        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame([self::text(BotText::ServiceRotateExpired), 'true'], [$this->popup(), $this->params(0)['show_alert'] ?? null]);

        // Going elsewhere in between spends a confirmation too — the list, or «انصراف» back to the service.
        foreach ([MenuHandler::subscriptionsCallback(1), $this->screenButton()] as $elsewhere) {
            $this->send($this->tap($this->screenButton('rotate')));
            $this->send($this->tap($elsewhere));
            $this->send($this->tap($this->screenButton('rotate', 'yes')));
            self::assertSame(['ali_1'], FakeProvider::$rotated, $elsewhere);
            self::assertSame(self::text(BotText::ServiceRotateExpired), $this->popup(), $elsewhere);
        }
    }

    public function testALinkThePanelChangedButTelegramWouldNotTakeIsStillAnnounced(): void
    {
        $this->send($this->tap($this->screenButton('rotate')));
        // The callback answer and the delete go through; the message with the new link is refused.
        $this->telegram()->reply(true, true);
        $this->telegram()->fail(400, 'Bad Request: message is too long');

        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        self::assertSame(['ali_1'], FakeProvider::$rotated, 'the panel side is done');
        self::assertSame('https://fake.test/sub/sub-rotated-1', $this->subscription->refresh()->subscription_url);
        self::assertSame(['answerCallbackQuery', 'deleteMessage', 'sendMessage', 'sendMessage'], $this->calls());
        self::assertSame(self::text(BotText::ServiceRotatedUnsent), $this->params(3)['text']);
        self::assertSame(
            [[['text' => self::text(BotText::ServiceLink), 'callback_data' => $this->screenButton('link'), 'style' => 'primary']]],
            $this->inlineKeyboard(3),
            'the button that sends the new link again',
        );
    }

    public function testAPanelThatCannotBeReachedLeavesTheLinkAsItWas(): void
    {
        $this->send($this->tap($this->screenButton('rotate')));
        FakeProvider::$unreachable = true;

        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls());
        self::assertSame(self::text(BotText::ServiceRotateFailed), $this->params(0)['text']);
        self::assertSame($this->screenButton(), $this->inlineKeyboard(0)[0][0]['callback_data']);
        self::assertSame(self::LINK, $this->subscription->refresh()->subscription_url);
    }

    public function testAPanelThatRotatesButServesNoNewLinkIsAFailedChange(): void
    {
        $this->send($this->tap($this->screenButton('rotate')));
        FakeProvider::$subscriptionBase = null; // the panel's subscription server went off: no link for the new credentials

        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        self::assertSame(['ali_1'], FakeProvider::$rotated);
        self::assertSame(self::text(BotText::ServiceRotateFailed), $this->params(0)['text'], 'without a new link there is nothing to hand over');
        self::assertSame(self::LINK, $this->subscription->refresh()->subscription_url);
    }

    public function testAnInactiveServiceCannotChangeItsLink(): void
    {
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();
        $this->send($this->tap($this->screenButton('rotate')));
        self::assertSame(self::text(BotText::ServiceInactive), $this->params(0)['text'], 'no confirmation is offered');

        // Switched off between the confirmation and the "yes".
        $this->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
        $this->send($this->tap($this->screenButton('rotate')));
        $this->subscription->forceFill(['status' => SubscriptionStatus::Disabled])->save();
        $this->send($this->tap($this->screenButton('rotate', 'yes')));

        self::assertSame(['answerCallbackQuery'], $this->calls());
        self::assertSame(self::text(BotText::ServiceInactive), $this->popup());
        self::assertSame([], FakeProvider::$rotated);
    }

    /** One of the service's buttons — its screen when no action is given. */
    private function screenButton(string ...$action): string
    {
        return SubscriptionHandler::serviceCallback($this->subscription->id, ...$action);
    }

    /**
     * The service screen as the customer should read it: BotText::ServiceDetails with ali_1's values — `$values` over
     * them —, then the hint at «تغییر لینک» (when the panel can rotate) and the stale flag (when the panel was not read).
     *
     * @param array<string, string> $values
     */
    private function screen(array $values, bool $stale = false, bool $rotates = true): string
    {
        $details = self::text(BotText::ServiceDetails, $values + [
            'status' => self::text(BotText::ServiceStatusActive),
            'client' => 'ali_1',
            'plan' => $this->plan->name,
            'server' => $this->server->name,
        ]);

        return implode("\n\n", array_filter([$details, $rotates ? self::text(BotText::ServiceRotateHint) : '', $stale ? self::text(BotText::ServiceStale) : '']));
    }

    /**
     * The screen's numbers as setUp's panel has them: 1.5 of 30 GB used, its deadline, the last connection.
     *
     * @return array<string, string>
     */
    private function asThePanelSays(bool $online): array
    {
        $used = Traffic::GIGABYTE + 512 * Traffic::MEGABYTE;

        return [
            'traffic' => Messages::traffic(30 * Traffic::GIGABYTE),
            'used' => Messages::bytes($used),
            'remaining' => Messages::bytes(30 * Traffic::GIGABYTE - $used) . ' (' . Messages::percent(30 * Traffic::GIGABYTE - $used, 30 * Traffic::GIGABYTE) . ')',
            'expires' => Messages::expiry(new \DateTimeImmutable('2026-10-19 12:00:00'), 30),
            'last_online' => Persian::date(new \DateTimeImmutable(self::LAST_ONLINE), withTime: true),
            'connection' => self::text($online ? BotText::ServiceOnline : BotText::ServiceOffline),
        ];
    }

    /**
     * The screen's numbers as the row last had them (2 of 30 GB used): nothing of the panel's presence.
     *
     * @return array<string, string>
     */
    private function asTheRowSays(): array
    {
        return [
            'traffic' => Messages::traffic(30 * Traffic::GIGABYTE),
            'used' => Messages::bytes(2 * Traffic::GIGABYTE),
            'remaining' => Messages::bytes(28 * Traffic::GIGABYTE) . ' (' . Messages::percent(28 * Traffic::GIGABYTE, 30 * Traffic::GIGABYTE) . ')',
            'expires' => Messages::expiry($this->subscription->expires_at, 30),
            'last_online' => self::text(BotText::ServiceUnknown),
            'connection' => self::text(BotText::ServiceUnknown),
        ];
    }

    /**
     * The screen's numbers of an unused 30 GB service, its end and last connection as given; offline.
     *
     * @return array<string, string>
     */
    private function unused(string $expires, string $lastOnline): array
    {
        return [
            'traffic' => Messages::traffic(30 * Traffic::GIGABYTE),
            'used' => self::text(BotText::ServiceUnused),
            'remaining' => Messages::bytes(30 * Traffic::GIGABYTE) . ' (' . Messages::percent(30 * Traffic::GIGABYTE, 30 * Traffic::GIGABYTE) . ')',
            'expires' => $expires,
            'last_online' => $lastOnline,
            'connection' => self::text(BotText::ServiceOffline),
        ];
    }
}
