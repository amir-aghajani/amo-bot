<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Settings\Services\Settings;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceClaims;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\ServiceClaimHandler;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «➕ افزودن سرویس» in the bot: a service support made on a panel by hand is added to the customer's account by its
 * subscription link or one of its configs — sent from anywhere, or after the button under «سرویس‌های من», which asks for
 * it. The service added comes with the way to its screen; every refusal is worded (one already the customer's with that
 * way too), and only a message holding no link keeps the link awaited. Sent at no step, only configs and links on a host
 * the shop's subscription links are on are looked for — a forwarded post's address asks no panel and costs no try —,
 * and one no panel has is any message. A link written at another step (a ticket) is that step's. A shop without the feature — switched off, or an agent's — answers a link as any message it does not
 * understand, and its «سرویس‌های من» has no button; too many tries are made to wait.
 */
final class BotServiceClaimTest extends BotTestCase
{
    /** The client's subscription link on the fake panel: its last part is the panel's subscription id. */
    private const LINK = 'https://fake.test/sub/abcd1234efgh5678';

    /** A vless config of a client whose subscription link is something else: found by its id. */
    private const CONFIG = 'vless://0B6E1F6A-1C3D-4B5E-9F7A-2D8C4E6B1A3F@de.example.com:443?type=tcp&security=reality#manual';

    private User $ali;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->ali = $this->customer();
        $this->server = $this->sellingServer();
        FakeProvider::put($this->server, new ClientInfo(
            name: 'manual_7',
            enabled: true,
            downloadBytes: Traffic::bytesOfGb(5),
            totalBytes: Traffic::bytesOfGb(50),
            expiry: Expiry::at(new \DateTimeImmutable('2026-11-11 12:00:00')),
            subscriptionUrl: self::LINK,
        ));
    }

    public function testALinkSentFromTheMenuAddsTheServiceWithTheWayToIt(): void
    {
        $this->claimsOn();

        $this->send($this->message('سلام، این لینک سرویس من است: ' . self::LINK));

        $service = Subscription::query()->sole();
        self::assertSame([$this->ali->id, $this->server->id, 'manual_7', self::LINK, null, false, SubscriptionStatus::Active, Traffic::bytesOfGb(50)], [
            $service->user_id,
            $service->server_id,
            $service->remote_name,
            $service->subscription_url,
            $service->plan_id,
            $service->is_test,
            $service->status,
            $service->traffic_limit_bytes,
        ], 'the client as its panel has it, without a plan');
        self::assertTrue(now()->equalTo($service->claimed_at), 'marked claimed');
        self::assertSame([self::text(BotText::ClaimAdded, ['client' => 'manual_7', 'server' => 'آلمان'])], $this->said());
        self::assertSame([[['text' => self::text(BotText::ReminderOpenService), 'callback_data' => SubscriptionHandler::serviceCallback($service->id)]]], $this->inlineKeyboard(0));

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));
        self::assertContains(SubscriptionHandler::serviceCallback($service->id), $this->callbacks(0), 'in «سرویس‌های من» now');
    }

    public function testAConfigOfTheServiceAddsItToo(): void
    {
        $this->claimsOn();
        FakeProvider::put($this->server, new ClientInfo(name: 'manual_8', enabled: true, subscriptionUrl: 'https://fake.test/sub/zzzz9999yyyy8888'));
        FakeProvider::$credentials[$this->server->id]['manual_8'] = ['0b6e1f6a-1c3d-4b5e-9f7a-2d8c4e6b1a3f'];

        $this->send($this->message(self::CONFIG));

        $service = Subscription::query()->sole();
        self::assertSame(['manual_8', $this->ali->id, 'https://fake.test/sub/zzzz9999yyyy8888'], [$service->remote_name, $service->user_id, $service->subscription_url], 'its id found it, its link kept');
        self::assertSame([self::text(BotText::ClaimAdded, ['client' => 'manual_8', 'server' => 'آلمان'])], $this->said());
    }

    public function testTheButtonUnderTheServicesAsksForTheLinkAndAwaitsItUntilOneComes(): void
    {
        $this->claimsOn();

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));
        self::assertSame([self::text(BotText::SubscriptionsEmpty)], $this->said(), 'no service yet');
        self::assertSame([[['text' => self::text(BotText::ClaimButton), 'callback_data' => ServiceClaimHandler::PREFIX, 'style' => 'primary']]], $this->inlineKeyboard(0), 'the button on the empty screen too');

        $this->send($this->tap(ServiceClaimHandler::PREFIX));
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'the prompt in place of the list');
        self::assertSame([self::text(BotText::ClaimPrompt)], $this->said());
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks(0), '«بازگشت» to «سرویس‌های من»');

        $this->send($this->message('سرویس من را اضافه کنید'));
        self::assertSame([self::text(BotText::ClaimNotALink)], $this->said());
        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-1', 'width' => 90]]]));
        self::assertSame([self::text(BotText::ClaimNotALink)], $this->said(), 'a picture is no link either');
        self::assertSame(0, Subscription::query()->count());

        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::ClaimAdded, ['client' => 'manual_7', 'server' => 'آلمان'])], $this->said(), 'the link still awaited');

        $this->send($this->message('ممنون'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'once added, nothing more is awaited');
    }

    public function testTheButtonSitsUnderTheServicesListAndNotUnderTheRenewals(): void
    {
        $this->claimsOn();
        $mine = $this->subscription($this->ali, $this->plan(on: $this->server), $this->server, 'ali_1');

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));
        self::assertSame([SubscriptionHandler::serviceCallback($mine->id), ServiceClaimHandler::PREFIX], $this->callbacks(0), 'under the services');

        $this->send($this->tap(MainMenu::RENEW));
        self::assertNotContains(ServiceClaimHandler::PREFIX, $this->callbacks(0));
    }

    public function testAWebAddressThatIsNoServicesLinkIsAnyMessageAtNoStepAndSaidSoWhereALinkIsAsked(): void
    {
        $this->claimsOn();

        $this->send($this->message('https://example.com'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'sent at no step: what the bot does not understand');

        $this->send($this->tap(ServiceClaimHandler::PREFIX));
        $this->send($this->message('https://example.com'));
        self::assertSame([self::text(BotText::ClaimNotALink)], $this->said(), 'where the link was asked for');
        self::assertSame([MenuHandler::subscriptionsCallback(1)], $this->callbacks(0));

        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::ClaimAdded, ['client' => 'manual_7', 'server' => 'آلمان'])], $this->said(), 'still awaited');
    }

    public function testAnOldButtonWhileSwitchedOffLeavesTheStepItInterrupts(): void
    {
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->tap(ServiceClaimHandler::PREFIX));
        self::assertSame([self::text(BotText::ClaimOff)], $this->said());

        $this->send($this->message('اتصال ندارم'));

        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'the ticket\'s first message is no longer awaited');
        self::assertSame(0, TicketMessage::query()->count());
    }

    public function testALinkSentLongAfterATicketChatIsAServiceSent(): void
    {
        $this->claimsOn();
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message('اتصال ندارم'));
        self::assertSame(1, TicketMessage::query()->count());
        Carbon::setTestNow(now()->addHour());

        $this->send($this->message(self::LINK));

        self::assertSame('manual_7', Subscription::query()->sole()->remote_name, 'the chat long left the ticket');
        self::assertSame(1, TicketMessage::query()->count());
    }

    public function testAPictureOfTheLinkCarriesItInItsCaptionWhereverItIsSent(): void
    {
        $this->claimsOn();
        $this->send($this->tap(ServiceClaimHandler::PREFIX));

        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-1', 'width' => 90]], 'caption' => self::LINK]));
        self::assertSame('manual_7', Subscription::query()->sole()->remote_name, 'where the link was asked for');

        Subscription::query()->delete();
        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-2', 'width' => 90]], 'caption' => self::LINK]));
        self::assertSame('manual_7', Subscription::query()->sole()->remote_name, 'and at no step');
    }

    public function testALinkNoPanelOfTheShopHasIsNotFoundWhereALinkIsAskedAndAnyMessageAtNoStep(): void
    {
        $this->claimsOn();

        $this->send($this->tap(ServiceClaimHandler::PREFIX));
        $this->send($this->message('https://fake.test/sub/nobody00000000'));
        self::assertSame([self::text(BotText::ClaimNotFound)], $this->said());

        $this->send($this->message('https://fake.test/sub/nobody00000000'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'sent at no step: a forwarded post\'s address may look like one');
        self::assertNotSame([], $this->replyKeyboard(0), 'with the menu');
        self::assertSame(0, Subscription::query()->count());
    }

    public function testAWebAddressOffTheShopsSubscriptionHostsAtNoStepIsAnyMessageAskingNoPanelAndCostingNoTry(): void
    {
        $this->claimsOn();
        $calls = [];
        FakeProvider::$onCall = static function (int $server, string $call) use (&$calls): void {
            $calls[] = $call;
        };

        for ($sent = 0; $sent <= ServiceClaims::TRIES; ++$sent) {
            // A channel's post forwarded: its address has a long part, as a service's token would.
            $this->send($this->message("پست جدید کانال: https://t.me/SomeChannel/123{$sent}"));
            self::assertSame([self::text(BotText::Unknown)], $this->said());
        }
        self::assertSame([], $calls, 'no panel asked');

        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::ClaimAdded, ['client' => 'manual_7', 'server' => 'آلمان'])], $this->said(), 'no try counted');
    }

    public function testASubscriptionLinkOnAHostTheShopsLinksUseIsClaimedAtNoStep(): void
    {
        $this->claimsOn();
        // The panel elsewhere than its links (fake.test).
        $this->server->update(['base_url' => 'https://panel.example:2053/base']);

        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'nothing of the shop is on that host yet');

        // Another customer's service on the server: its link is on that host.
        $this->subscription($this->customer(['telegram_id' => 9999]), $this->plan(on: $this->server), $this->server, 'reza_1');
        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::ClaimAdded, ['client' => 'manual_7', 'server' => 'آلمان'])], $this->said());

        // The links' prefix set on the server, and the panel's own address (where 3x-ui serves them unless set otherwise).
        $this->server->update(['meta' => ['subscription_url' => 'https://sub.example.com/s']]);
        foreach (['manual_8' => 'https://sub.example.com/s/qqqq1111wwww2222', 'manual_9' => 'https://panel.example:2096/sub/eeee3333rrrr4444'] as $name => $link) {
            FakeProvider::put($this->server, new ClientInfo(name: $name, enabled: true, subscriptionUrl: $link));
            $this->send($this->message($link));
            self::assertSame([self::text(BotText::ClaimAdded, ['client' => $name, 'server' => 'آلمان'])], $this->said());
        }
    }

    public function testAServiceAnotherCustomerHasIsTheirs(): void
    {
        $this->claimsOn();
        $reza = $this->customer(['telegram_id' => 9999]);
        $this->subscription($reza, $this->plan(on: $this->server), $this->server, 'manual_7');

        $this->send($this->message(self::LINK));

        self::assertSame([self::text(BotText::ClaimTaken)], $this->said());
        self::assertSame([$reza->id], Subscription::query()->pluck('user_id')->all(), 'left where it is');
    }

    public function testTheCustomersOwnServiceComesWithTheWayToIt(): void
    {
        $this->claimsOn();
        $mine = $this->mirrored($this->subscription($this->ali, $this->plan(on: $this->server), $this->server, 'ali_home_1'));

        $this->send($this->message($mine->subscription_url));

        self::assertSame([self::text(BotText::ClaimYours)], $this->said());
        self::assertSame([SubscriptionHandler::serviceCallback($mine->id)], $this->callbacks(0));
        self::assertSame(1, Subscription::query()->count());
    }

    public function testAPanelThatDoesNotAnswerMakesTheLinkUncheckable(): void
    {
        $this->claimsOn();
        FakeProvider::$down = [$this->server->id];

        $this->send($this->message(self::LINK));

        self::assertSame([self::text(BotText::ClaimUnavailable)], $this->said());
        self::assertSame(0, Subscription::query()->count());
    }

    public function testAClientItsPanelServesNoSubscriptionLinkForIsNotAdded(): void
    {
        $this->claimsOn();
        FakeProvider::put($this->server, new ClientInfo(name: 'manual_8', enabled: true));
        FakeProvider::$credentials[$this->server->id]['manual_8'] = ['0b6e1f6a-1c3d-4b5e-9f7a-2d8c4e6b1a3f'];

        $this->send($this->message(self::CONFIG));

        self::assertSame([self::text(BotText::ClaimNoLink)], $this->said());
        self::assertSame(0, Subscription::query()->count());
    }

    public function testWhileSwitchedOffALinkIsAnsweredAsBefore(): void
    {
        $this->send($this->message(self::LINK));

        self::assertSame([self::text(BotText::Unknown)], $this->said(), '«متوجه نشدم» with the menu');
        self::assertNotSame([], $this->replyKeyboard(0));
        self::assertSame(0, Subscription::query()->count());

        $this->send($this->tap(MainMenu::SUBSCRIPTIONS));
        self::assertSame([], $this->callbacks(0), 'no button under «سرویس‌های من»');

        // A button from before it was switched off, and the link sent after it.
        $this->send($this->tap(ServiceClaimHandler::PREFIX));
        self::assertSame([self::text(BotText::ClaimOff)], $this->said());
        $this->claimsOn();
        $this->send($this->tap(ServiceClaimHandler::PREFIX));
        $this->claimsOn(false);
        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::ClaimOff)], $this->said());
        $this->send($this->message(self::LINK));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'nothing awaited any more');
        self::assertSame(0, Subscription::query()->count());
    }

    public function testAnAgentsShopTakesNoServiceByItsLink(): void
    {
        $this->claimsOn();
        $bot = $this->agentBot();
        // An agent's shop has no such switch; a value stored for it all the same changes nothing.
        CurrentBot::run($bot, fn() => $this->service(Settings::class)->set('claims.enabled', true));

        $this->sendTo($bot, $this->message(self::LINK));

        self::assertSame([self::text(BotText::Unknown)], $this->said());
        self::assertSame(0, Subscription::acrossShops()->count());

        $this->sendTo($bot, $this->tap(MainMenu::SUBSCRIPTIONS));
        self::assertNotContains(ServiceClaimHandler::PREFIX, $this->callbacks(0));
    }

    public function testTooManyTriesAreMadeToWait(): void
    {
        $this->claimsOn();
        for ($try = 1; $try <= ServiceClaims::TRIES; ++$try) {
            $this->send($this->message("https://fake.test/sub/nobody0000000{$try}"));
        }
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'none found, each a try all the same');

        $this->send($this->message(self::LINK));

        self::assertSame([self::text(BotText::ClaimWait, ['minutes' => '۱۰'])], $this->said(), 'the window\'s ten minutes');
        self::assertSame(0, Subscription::query()->count());
    }

    public function testALinkWrittenInATicketIsTheTickets(): void
    {
        $this->claimsOn();
        $this->send($this->tap(TicketHandler::START));

        $this->send($this->message('این لینک وصل نمی‌شود: ' . self::LINK));

        self::assertSame('این لینک وصل نمی‌شود: ' . self::LINK, TicketMessage::query()->sole()->body);
        self::assertSame(0, Subscription::query()->count());
        self::assertNotContains(self::text(BotText::ClaimAdded, ['client' => 'manual_7', 'server' => 'آلمان']), $this->said());
    }
}
