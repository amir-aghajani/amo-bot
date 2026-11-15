<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Models\BotChannel;
use App\Modules\Telegram\Reports\ReportGroupState;
use App\Modules\Telegram\Session\SessionStore;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * «📢 کانال‌های اجباری» and «📋 گروه گزارش‌ها» from the bot (Sections\Bot): the rule's switch, a channel added by its link
 * (the panel's check of the bot's rights answering), checked, moved and removed, asked first; the report group connected
 * by its link, tested and disconnected, asked first.
 */
final class BotAreaChannelsTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::OWNER, 'role' => UserRole::Admin]);
        $this->linkOwner(self::OWNER);
    }

    public function testAChannelIsAddedCheckedMovedAndRemoved(): void
    {
        $first = $this->channel(-1001000000009, 'کانال قدیمی');

        $this->send($this->tap(Nav::to('ch'), chat: self::OWNER));
        self::assertStringContainsString('خاموش است و این لیست اعمال نمی‌شود', $this->said()[0]);
        self::assertContains(Nav::to('ch', 'join', 1), $this->callbacks(0), 'the switch carries the value it sets');
        $this->send($this->tap(Nav::to('ch', 'join', 1), chat: self::OWNER));
        self::assertFalse($this->service(BotSettings::class)->joinRequired(), 'on holds customers back: asked first');
        self::assertContains(Nav::to('ch', 'join', 1, 'y'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('ch', 'join', 1, 'y'), chat: self::OWNER));
        self::assertTrue($this->service(BotSettings::class)->joinRequired(), 'the rule, through the panel\'s route');
        $this->send($this->tap(Nav::to('ch', 'join', 1, 'y'), chat: self::OWNER));
        self::assertTrue($this->service(BotSettings::class)->joinRequired(), 'a second «yes»: still on, never flipped back');

        // A channel's link asked: a menu button's label leaves the question, never taken as the link.
        $this->send($this->tap(Nav::to('ch', 'add'), chat: self::OWNER));
        $this->send($this->message(Messages::MENU_SUPPORT, chat: self::OWNER));
        self::assertNotSame(Admin::INPUT, $this->service(SessionStore::class)->load(self::OWNER)->state());
        self::assertSame(1, BotChannel::query()->count());

        $this->telegram()->on('getChat', static fn(): array => ['id' => -1001000000001, 'type' => 'channel', 'title' => 'اخبار فروشگاه', 'username' => 'shop_news']);
        $this->telegram()->on('getChatMember', static fn(): array => ['status' => 'administrator', 'user' => ['id' => FakeTelegram::BOT_ID]]);
        $this->send($this->tap(Nav::to('ch', 'add'), chat: self::OWNER));
        $this->send($this->message('https://t.me/shop_news', chat: self::OWNER));
        $news = BotChannel::query()->where('title', 'اخبار فروشگاه')->sole();
        self::assertStringContainsString('اضافه شد', $this->said()[0]);

        $this->send($this->tap(Nav::to('chv', 'show', $news->id), chat: self::OWNER));
        self::assertStringContainsString('@shop_news', $this->said()[0]);
        self::assertContains(Nav::to('chv', 'up', $news->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('chv', 'up', $news->id), chat: self::OWNER));
        self::assertSame([$news->id, $first->id], BotChannel::query()->orderBy('sort')->pluck('id')->all());

        $this->send($this->tap(Nav::to('chv', 'check', $news->id), chat: self::OWNER));
        self::assertStringContainsString('مدیر است', (string) $this->popup());

        $this->send($this->tap(Nav::to('chv', 'del', $news->id), chat: self::OWNER));
        self::assertStringContainsString('ربات از کانال بیرون نمی‌رود', $this->said()[0], 'asked first, in the panel\'s words');
        $this->send($this->tap(Nav::to('chv', 'gone', $news->id), chat: self::OWNER));
        self::assertNull(BotChannel::query()->find($news->id));
    }

    public function testAChannelThePanelRefusesIsSaidOverTheQuestion(): void
    {
        $this->telegram()->on('getChat', static fn(): array => ['id' => -1001000000002, 'type' => 'channel', 'title' => 'Other', 'username' => 'other']);
        $this->telegram()->on('getChatMember', static fn(): array => ['status' => 'member']);

        $this->send($this->tap(Nav::to('ch', 'add'), chat: self::OWNER));
        $this->send($this->message('@other', chat: self::OWNER));
        self::assertStringStartsWith('<b>⚠️', $this->said()[0]);
        self::assertSame(0, BotChannel::query()->count());
    }

    public function testTheReportGroupIsConnectedTestedAndDisconnected(): void
    {
        $this->send($this->tap(Nav::to('rg'), chat: self::OWNER));
        self::assertContains(Nav::to('rg', 'link'), $this->callbacks(0), 'not connected: a link to make');
        self::assertContains(Nav::to('rgt'), $this->callbacks(0), 'the topics\' switches');

        $this->send($this->tap(Nav::to('rg', 'link'), chat: self::OWNER));
        self::assertStringContainsString('t.me/amo_shop_bot?startgroup=', $this->said()[0]);
        self::assertContains(Nav::to('rg', 'link'), $this->callbacks(0), 'a fresh one');

        $this->reportGroup();
        $this->send($this->tap(Nav::to('rg'), chat: self::OWNER));
        self::assertStringContainsString('✅ وصل است', $this->said()[0]);
        self::assertContains(Nav::to('rg', 'test'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('rg', 'test'), chat: self::OWNER));
        self::assertStringContainsString('پیام تست به', (string) $this->popup());

        $this->send($this->tap(Nav::to('rg', 'cut'), chat: self::OWNER));
        self::assertStringContainsString('دور ریخته می‌شوند', $this->said()[0], 'asked first');
        $this->send($this->tap(Nav::to('rg', 'cutd'), chat: self::OWNER));
        self::assertNull($this->service(ReportGroupState::class)->chatId());
    }

    public function testAShopsAdminHasNeither(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);

        $this->send($this->tap(Nav::to('ch', 'join', 1, 'y'), chat: 7100));
        self::assertFalse($this->service(BotSettings::class)->joinRequired());
        $this->send($this->tap(Nav::to('rg', 'cutd'), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0]);
    }
}
