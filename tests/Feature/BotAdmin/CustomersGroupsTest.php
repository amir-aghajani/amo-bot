<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\CustomerGroup;
use Tests\BotTestCase;

/**
 * «👥 گروه‌های کاربران» in the bot's management: the groups in the admin's order with their members, a group added (its
 * name typed, «همکار» switched on the form), renamed, switched, moved and deleted — asked first — through the panel's
 * routes; its customers on the users list; the `catalog` grant for a shop's admin.
 */
final class CustomersGroupsTest extends BotTestCase
{
    private const OWNER = 7001;
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testManyGroupsGoByPagesInTheirPickers(): void
    {
        $this->owner();
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $groups = array_map(fn(int $n): CustomerGroup => $this->customerGroup('گروه ' . $n), range(1, 110));
        $last = $groups[109];

        // Telegram takes at most 100 buttons a message: a page of them, and the way to the next.
        $this->send($this->tap(Nav::to('usg', 'show', $ali->id), chat: self::OWNER));
        self::assertLessThan(100, count($this->window()));
        self::assertNotContains(Nav::to('usg', 'sw', $ali->id, $last->id, 1), $this->window());
        self::assertContains(Nav::to('usg', 'pg', $ali->id, 2), $this->window(), 'the next page');
        $this->send($this->tap(Nav::to('usg', 'pg', $ali->id, 3), chat: self::OWNER));
        $this->send($this->tap(Nav::to('usg', 'sw', $ali->id, $last->id, 1), chat: self::OWNER));
        self::assertTrue($ali->groups()->whereKey($last->id)->exists());
        self::assertContains(Nav::to('usg', 'sw', $ali->id, $last->id, 0), $this->window(), 'still on the page it was switched on');

        $this->send($this->tap(Nav::to('usf', 'show', 'g'), chat: self::OWNER));
        self::assertLessThan(100, count($this->window()));
        self::assertContains(Nav::to('usf', 'show', 'g', 2), $this->window());
        $this->send($this->tap(Nav::to('usf', 'show', 'g', 3), chat: self::OWNER));
        self::assertContains(Nav::to('usf', 'g', $last->id), $this->window());
    }

    public function testTheOwnerAddsAPartnerGroupThenRenamesMovesAndDeletesIt(): void
    {
        $this->owner();
        $vip = $this->customerGroup('VIP');

        $this->send($this->tap(Nav::to('hs'), chat: self::OWNER));
        self::assertContains(Nav::to('ug'), $this->window(), 'in the shop menu');
        $this->send($this->tap(Nav::to('ug'), chat: self::OWNER));
        self::assertContains(Nav::to('ugv', 'show', $vip->id), $this->window());

        $this->send($this->tap(Nav::to('ug', 'add', 'new'), chat: self::OWNER));
        $this->send($this->message('همکاران', chat: self::OWNER));
        self::assertStringContainsString('همکاران', $this->screen());
        self::assertContains(Nav::to('ug', 'ap', 1), $this->window(), 'the switch carries what it sets');
        $this->send($this->tap(Nav::to('ug', 'ap', 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('ug', 'ap', 1), chat: self::OWNER));
        self::assertContains(Nav::to('ug', 'ap', 0), $this->window(), 'a second tap of a stale button sets it the same');
        self::assertSame(1, CustomerGroup::query()->count(), 'nothing added before «✔️»');
        $this->send($this->tap(Nav::to('ug', 'make'), chat: self::OWNER));
        $partners = CustomerGroup::query()->where('name', 'همکاران')->sole();
        self::assertTrue($partners->agent_pricing, 'switched on the form');
        self::assertContains(Nav::to('ugv', 'up', $partners->id), $this->window(), 'its own screen, last in order');

        $this->send($this->tap(Nav::to('ugv', 'up', $partners->id), chat: self::OWNER));
        self::assertSame([$partners->id, $vip->id], CustomerGroup::query()->oldest('sort')->pluck('id')->all());

        $this->send($this->tap(Nav::to('ugv', 'name', $partners->id), chat: self::OWNER));
        $this->send($this->message('VIP', chat: self::OWNER));
        self::assertStringContainsString('گروهی با این نام وجود دارد', $this->screen(), 'the panel\'s own check, the question waiting');
        $this->send($this->message('همکاران ویژه', chat: self::OWNER));
        self::assertSame('همکاران ویژه', $partners->refresh()->name);

        $this->send($this->tap(Nav::to('ugv', 'ap', $partners->id, 0), chat: self::OWNER));
        self::assertFalse($partners->refresh()->agent_pricing);
        $this->send($this->tap(Nav::to('ugv', 'ap', $partners->id, 0), chat: self::OWNER));
        self::assertFalse($partners->refresh()->agent_pricing, 'a double tap does not switch it back on');

        $this->send($this->tap(Nav::to('ugv', 'del', $partners->id), chat: self::OWNER));
        self::assertStringContainsString('هیچ مشتری‌ای در این گروه نیست', $this->screen());
        self::assertStringContainsString('این گروه حذف شود؟', $this->screen());
        self::assertContains(Nav::to('ugv', 'gone', $partners->id), $this->window(), 'asked first');
        $this->send($this->tap(Nav::to('ugv', 'gone', $partners->id), chat: self::OWNER));
        self::assertNull(CustomerGroup::query()->find($partners->id));
    }

    public function testAGroupsCustomersOpenOnTheUsersList(): void
    {
        $this->owner();
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $sara = $this->customer(['telegram_id' => 2222, 'first_name' => 'Sara']);
        $vip = $this->customerGroup('VIP', [$ali]);

        $this->send($this->tap(Nav::to('ugv', 'users', $vip->id), chat: self::OWNER));
        self::assertStringContainsString('فقط گروه «VIP»', $this->screen());
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->window());
        self::assertNotContains(Nav::to('usv', 'show', $sara->id), $this->window());
    }

    public function testAShopsAdminChangesTheGroupsOnlyWithTheCatalogue(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
        $vip = $this->customerGroup('VIP');

        $this->send($this->tap(Nav::to('ug'), chat: self::STAFF));
        self::assertContains(Nav::to('ugv', 'show', $vip->id), $this->window(), 'the groups read');
        self::assertNotContains(Nav::to('ug', 'add', 'new'), $this->window());
        $this->send($this->tap(Nav::to('ugv', 'show', $vip->id), chat: self::STAFF));
        self::assertNotContains(Nav::to('ugv', 'del', $vip->id), $this->window());

        $this->send($this->tap(Nav::to('ugv', 'gone', $vip->id), chat: self::STAFF));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup(), 'the door says no');
        self::assertNotNull(CustomerGroup::query()->find($vip->id));

        $this->botSettings('bot_admins', ['bot_admins_grant_catalog' => true]);
        $this->send($this->tap(Nav::to('ugv', 'show', $vip->id), chat: self::STAFF));
        self::assertContains(Nav::to('ugv', 'del', $vip->id), $this->window(), 'granted');
    }

    /** The account OWNER linked as the owner — the bot's admin too, as the link makes it. */
    private function owner(): void
    {
        $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir', 'role' => UserRole::Admin]);
        $this->linkOwner(self::OWNER);
    }

    /** @return list<string> The window's buttons, as the last screen drew them. */
    private function window(): array
    {
        $calls = $this->calls();
        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if (in_array($calls[$i], ['sendMessage', 'editMessageText'], true)) {
                return $this->callbacks($i);
            }
        }

        return [];
    }

    /** What the window says now. */
    private function screen(): string
    {
        $calls = $this->calls();
        for ($i = count($calls) - 1; $i >= 0; $i--) {
            if (in_array($calls[$i], ['sendMessage', 'editMessageText'], true)) {
                return $this->params($i)['text'] ?? '';
            }
        }

        return '';
    }
}
