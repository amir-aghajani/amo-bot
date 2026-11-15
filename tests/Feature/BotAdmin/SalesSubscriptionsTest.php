<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\Traffic;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * «📡 اشتراک‌ها» in the bot's management (Telegram\Admin\Sections\Sales): a service synced, switched off with a note typed
 * for the customer and on again; extended only with the grant, a preset and an amount typed; moved to another server —
 * on without a previous server that does not answer —, several at once; handed to another customer only with the grant;
 * deleted, from the shop alone when its panel does not answer; and the list narrowed by server — each through the
 * panel's own routes.
 */
final class SalesSubscriptionsTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->fakePanel();
        $this->withoutQr();
        $this->ali = $this->customer(['first_name' => 'Ali', 'username' => 'ali_r']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testAServiceIsSyncedSwitchedOffWithANoteAndOnAgain(): void
    {
        $this->owner();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));

        $this->send($this->tap(Nav::to('sb'), chat: 7001));
        $this->send($this->tap(Nav::to('sbv', 'show', $service->id), chat: 7001));
        self::assertStringContainsString('<code>https://fake.test/sub/ali_r_1</code>', $this->said()[0], 'the link, copied at a tap');
        self::assertContains(Nav::to('sbv', 'sy', $service->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('sbv', 'sy', $service->id), chat: 7001));
        self::assertStringContainsString('از پنل به‌روز شد', (string) $this->popup());
        self::assertNotNull($service->refresh()->last_synced_at, 'read from its panel');

        $this->send($this->tap(Nav::to('sbv', 'di', $service->id), chat: 7001));
        self::assertStringContainsString('غیرفعال شود؟', $this->said()[0], 'asked first');
        $this->send($this->tap(Nav::to('sbv', 'din', $service->id), chat: 7001));
        $this->send($this->message('استفاده خارج از قوانین', chat: 7001));
        self::assertSame(SubscriptionStatus::Disabled, $service->refresh()->status, 'switched off through the panel\'s route');
        self::assertContains(Nav::to('sbv', 'en', $service->id), $this->callbacks($this->windowCall()));

        $this->send($this->tap(Nav::to('sbv', 'en', $service->id), chat: 7001));
        self::assertSame(SubscriptionStatus::Active, $service->refresh()->status);
        self::assertStringContainsString('دوباره فعال شد', (string) $this->popup());
    }

    public function testAnAdminExtendsAServiceOnlyWithTheGrant(): void
    {
        $this->staff();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));
        $ends = $service->expires_at;

        $this->send($this->tap(Nav::to('sbv', 'show', $service->id), chat: 7100));
        self::assertNotContains(Nav::to('sbe', 'new', $service->id), $this->callbacks(0), 'extending is a grant');
        $this->send($this->tap(Nav::to('sbe', 'go', $service->id), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');

        $this->botSettings('bot_admins', ['bot_admins_grant_extend' => true]);
        $this->send($this->tap(Nav::to('sbv', 'show', $service->id), chat: 7100));
        self::assertContains(Nav::to('sbe', 'new', $service->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('sbe', 'new', $service->id), chat: 7100));
        self::assertNotContains(Nav::to('sbe', 'go', $service->id), $this->callbacks(0), 'nothing to give yet');
        $this->send($this->tap(Nav::to('sbe', 'd', $service->id, 7), chat: 7100));
        self::assertContains('● ۷ روز', $this->buttonLabels(0), 'the preset picked');
        $this->send($this->tap(Nav::to('sbe', 'gt', $service->id), chat: 7100));
        $this->send($this->message('۵ گیگ', chat: 7100));
        self::assertStringContainsString('۵ گیگابایت', implode("\n", $this->said()));

        $this->send($this->tap(Nav::to('sbe', 'go', $service->id), chat: 7100));
        $service->refresh();
        self::assertSame($ends?->copy()->addDays(7)->getTimestamp(), $service->expires_at?->getTimestamp(), 'extended through the panel\'s route');
        self::assertSame(35 * Traffic::GIGABYTE, (int) $service->traffic_limit_bytes);
        self::assertStringContainsString('زمان و حجم به سرویس', (string) $this->popup());
    }

    public function testAServiceMovesOnWithoutAPreviousServerThatDoesNotAnswer(): void
    {
        $this->owner();
        $target = $this->target();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));

        $this->send($this->tap(Nav::to('sbv', 'mv', $service->id), chat: 7001));
        self::assertContains(Nav::to('sbv', 'mvs', $service->id, $target->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('sbv', 'mvs', $service->id, $this->server->id), $this->callbacks(0), 'not where it is');

        $this->send($this->tap(Nav::to('sbv', 'mvs', $service->id, $target->id), chat: 7001));
        self::assertStringContainsString('منتقل شود؟', $this->said()[0], 'asked first');

        FakeProvider::$down = [$this->server->id];
        $this->send($this->tap(Nav::to('sbv', 'mvg', $service->id, $target->id), chat: 7001));
        self::assertStringContainsString('بدون حذف از', $this->said()[0], 'the previous server\'s silence asks');
        self::assertSame($this->server->id, $service->refresh()->server_id, 'nothing moved unless the admin says so');

        $this->send($this->tap(Nav::to('sbv', 'mvl', $service->id, $target->id), chat: 7001));
        self::assertSame($target->id, $service->refresh()->server_id, 'moved through the panel\'s route');
        self::assertStringContainsString('منتقل شد', (string) $this->popup());
    }

    public function testSeveralServicesMoveTogether(): void
    {
        $this->owner();
        $target = $this->target();
        $first = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));
        $second = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_2'));

        $this->send($this->tap(Nav::to('sb'), chat: 7001));
        self::assertContains(Nav::to('sbm'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('sbm'), chat: 7001));
        $this->send($this->tap(Nav::to('sbm', 'pa'), chat: 7001));
        self::assertStringContainsString('۲ سرویس انتخاب شده', $this->said()[0]);

        $this->send($this->tap(Nav::to('sbm', 'pick'), chat: 7001));
        self::assertContains(Nav::to('sbm', 'to', $target->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('sbm', 'to', $target->id), chat: 7001));
        self::assertStringContainsString('۲ سرویس به', $this->said()[0], 'asked first');

        $this->send($this->tap(Nav::to('sbm', 'go', $target->id), chat: 7001));
        self::assertSame([$target->id, $target->id], [$first->refresh()->server_id, $second->refresh()->server_id], 'each moved through the panel\'s route');
        self::assertStringContainsString('۲ منتقل شد', implode("\n", $this->said()));

        $this->send($this->tap(Nav::to('sbm', 'end'), chat: 7001));
        self::assertContains(Nav::to('sbm'), $this->callbacks(0), 'back on the services list');
    }

    public function testAServiceIsHandedToAnotherCustomerOnlyWithTheGrant(): void
    {
        $this->staff();
        $sara = $this->customer(['telegram_id' => 5252, 'first_name' => 'Sara']);
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));

        $this->send($this->tap(Nav::to('sbv', 'show', $service->id), chat: 7100));
        self::assertNotContains(Nav::to('sbv', 'tr', $service->id), $this->callbacks(0), 'handing a service over is a grant');
        $this->send($this->tap(Nav::to('sbv', 'trg', $service->id, 5252), chat: 7100));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup(), 'the door refuses it');

        $this->botSettings('bot_admins', ['bot_admins_grant_delete' => true]);
        $this->send($this->tap(Nav::to('sbv', 'tr', $service->id), chat: 7100));
        self::assertStringContainsString('شناسه عددی تلگرام مشتری جدید', $this->said()[0]);

        $this->send($this->message('sara', chat: 7100));
        self::assertStringContainsString('دوباره بفرستید', implode("\n", $this->said()), 'refused by the server\'s own look-up, the question still waiting');

        $this->send($this->message('۵۲۵۲', chat: 7100));
        self::assertStringContainsString('Sara', implode("\n", $this->said()), 'whom it names, before anything is sent');
        self::assertSame($this->ali->id, $service->refresh()->user_id);

        $this->send($this->tap(Nav::to('sbv', 'trg', $service->id, 5252), chat: 7100));
        self::assertSame($sara->id, $service->refresh()->user_id, 'handed over through the panel\'s route');
    }

    public function testAServiceWhosePanelDoesNotAnswerIsDeletedFromTheShopAlone(): void
    {
        $this->owner();
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1'));

        $this->send($this->tap(Nav::to('sbv', 'de', $service->id), chat: 7001));
        self::assertStringContainsString('این کار برگشت ندارد', $this->said()[0], 'asked first, in the panel\'s words');

        FakeProvider::$down = [$this->server->id];
        $this->send($this->tap(Nav::to('sbv', 'deg', $service->id), chat: 7001));
        self::assertStringContainsString('فقط در فروشگاه حذف شود؟', $this->said()[0]);
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists(), 'nothing deleted unless the admin says so');

        $this->send($this->tap(Nav::to('sbv', 'dex', $service->id), chat: 7001));
        self::assertFalse(Subscription::query()->whereKey($service->id)->exists(), 'deleted through the panel\'s route');
        self::assertStringContainsString('باید خودتان حذفش کنید', (string) $this->popup());
        self::assertContains(Nav::to('sbm'), $this->callbacks($this->windowCall()), 'the services list');
    }

    public function testTheListIsNarrowedByServer(): void
    {
        $this->owner();
        $target = $this->target();
        $here = $this->subscription($this->ali, $this->plan, $this->server, 'ali_r_1');
        $there = $this->subscription($this->ali, $this->plan, $target, 'ali_r_2');

        $this->send($this->tap(Nav::to('sbf'), chat: 7001));
        self::assertContains(Nav::to('sbf', 's', 'server', $target->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('sbf', 's', 'server', $target->id), chat: 7001));
        $this->send($this->tap(Nav::to('sb'), chat: 7001));
        self::assertStringContainsString('سرور: هلند', $this->said()[0]);
        self::assertContains(Nav::to('sbv', 'show', $there->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('sbv', 'show', $here->id), $this->callbacks(0));
    }

    public function testAnAgentExtendsFromTheirBotsTrafficAndSeesNoMainShopKinds(): void
    {
        $bot = $this->agentBot(traffic: 100);
        $service = CurrentBot::run($bot, fn(): Subscription => $this->mirrored($this->subscription($this->customer(['telegram_id' => 2001, 'first_name' => 'Reza']), $this->plan(['name' => 'پلن نماینده']), $this->server, 'reza_1')));

        $this->sendTo($bot, $this->tap(Nav::to('sbe', 'show', $service->id), chat: self::AGENT_TELEGRAM_ID));
        self::assertStringContainsString('از حجم ربات این فروشگاه کم می‌شود (۱۰۰ گیگابایت مانده)', $this->said()[0], 'an agent\'s GB come out of their bot');

        $this->sendTo($bot, $this->tap(Nav::to('sbf'), chat: self::AGENT_TELEGRAM_ID));
        self::assertNotContains(Nav::to('sbf', 's', 'kind', 'claimed'), $this->callbacks(0), 'only the main shop takes services by their link');
        $this->sendTo($bot, $this->tap(Nav::to('orf'), chat: self::AGENT_TELEGRAM_ID));
        self::assertNotContains(Nav::to('orf', 's', 'type', 'traffic'), $this->callbacks(0), 'an agent\'s traffic is bought in the main shop');
    }

    /** A second server services may move to. */
    private function target(): Server
    {
        $target = $this->fakeServer('هلند');
        $this->inbound($target, '7');

        return $target;
    }

    /** One of the shop's admins, 7100, managing from the bot. */
    private function staff(): void
    {
        $this->customer(['telegram_id' => 7100, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
    }

    /** The account 7001 linked as the owner. */
    private function owner(): void
    {
        $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkOwner(7001);
    }
}
