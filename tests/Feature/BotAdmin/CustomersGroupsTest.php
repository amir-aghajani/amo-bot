<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\CustomerGroup;
use Tests\BotTestCase;

/**
 * «👥 گروه‌های کاربران» in the bot's management: the groups in the admin's order with their members, a group added (its
 * name typed, «همکار» switched on the form), renamed, switched, moved and deleted — asked first — through the panel's
 * routes; its customers on the users list; a shop's admin changing them as the owner does, but for «همکار» prices of
 * their own account.
 */
final class CustomersGroupsTest extends BotTestCase
{
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testManyGroupsGoByPagesInTheirPickers(): void
    {
        $this->owner(UserRole::Admin);
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
        self::assertStringNotContainsString('گروه 110', $this->screen(), 'said by its switch on this page, not again');
        $this->send($this->tap(Nav::to('usg', 'pg', $ali->id, 1), chat: self::OWNER));
        self::assertStringContainsString('عضو ۱ · در صفحه‌های دیگر: گروه 110', $this->screen(), 'a page shows some switches: the groups they are in elsewhere named');

        $this->send($this->tap(Nav::to('usf', 'show', 'g'), chat: self::OWNER));
        self::assertLessThan(100, count($this->window()));
        self::assertContains(Nav::to('usf', 'show', 'g', 2), $this->window());
        $this->send($this->tap(Nav::to('usf', 'show', 'g', 3), chat: self::OWNER));
        self::assertContains(Nav::to('usf', 'g', $last->id), $this->window());
    }

    public function testTheOwnerAddsAPartnerGroupThenRenamesMovesAndDeletesIt(): void
    {
        $this->owner(UserRole::Admin);
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
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $sara = $this->customer(['telegram_id' => 2222, 'first_name' => 'Sara']);
        $vip = $this->customerGroup('VIP', [$ali]);
        // The users list left searched and narrowed by a pill: the group's customers are shown whole all the same.
        $this->send($this->tap(Nav::to('us', 'f', 'status', 'banned'), chat: self::OWNER));
        $this->send($this->tap(Nav::to('us', 'search'), chat: self::OWNER));
        $this->send($this->message('sara', chat: self::OWNER));

        $this->send($this->tap(Nav::to('ugv', 'users', $vip->id), chat: self::OWNER));
        self::assertStringContainsString('۱ کاربر · گروه: VIP', $this->screen(), 'as many as the group\'s card counts');
        self::assertStringNotContainsString('«sara»', $this->screen());
        self::assertContains(Nav::to('usv', 'show', $ali->id), $this->window());
        self::assertNotContains(Nav::to('usv', 'show', $sara->id), $this->window());
    }

    public function testACustomersGroupsOpenOnTheirOwnFirstPage(): void
    {
        $this->owner(UserRole::Admin);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $sara = $this->customer(['telegram_id' => 2222, 'first_name' => 'Sara']);
        $groups = array_map(fn(int $n): CustomerGroup => $this->customerGroup('گروه ' . $n), range(1, 50));

        $this->send($this->tap(Nav::to('usg', 'pg', $ali->id, 2), chat: self::OWNER));
        self::assertContains(Nav::to('usg', 'sw', $ali->id, $groups[49]->id, 1), $this->window(), 'Ali\'s second page');
        $this->send($this->tap(Nav::to('usg', 'show', $sara->id), chat: self::OWNER));
        self::assertContains(Nav::to('usg', 'sw', $sara->id, $groups[0]->id, 1), $this->window(), 'Sara\'s groups from their first page');
        $this->send($this->tap(Nav::to('usg', 'show', $ali->id), chat: self::OWNER));
        self::assertContains(Nav::to('usg', 'sw', $ali->id, $groups[0]->id, 1), $this->window());
    }

    public function testAShopsAdminChangesTheGroupsAsTheOwnerDoes(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
        $vip = $this->customerGroup('VIP');

        $this->send($this->tap(Nav::to('ug'), chat: self::STAFF));
        self::assertContains(Nav::to('ugv', 'show', $vip->id), $this->window());
        self::assertContains(Nav::to('ug', 'add', 'new'), $this->window(), 'no grant of the catalogue asked');
        $this->send($this->tap(Nav::to('ugv', 'show', $vip->id), chat: self::STAFF));
        self::assertContains(Nav::to('ugv', 'del', $vip->id), $this->window());

        $this->send($this->tap(Nav::to('ugv', 'ap', $vip->id, 1), chat: self::STAFF));
        self::assertTrue($vip->refresh()->agent_pricing, 'made a «همکار» group');
        $this->send($this->tap(Nav::to('ugv', 'gone', $vip->id), chat: self::STAFF));
        self::assertNull(CustomerGroup::query()->find($vip->id), 'deleted through the panel\'s route');
    }

    public function testAnAdminsOwnAccountIsPutInNoPartnerGroupByThemselves(): void
    {
        $staff = $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
        $this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]);
        $ali = $this->customer(['telegram_id' => 1111, 'first_name' => 'Ali']);
        $vip = $this->customerGroup('VIP');
        $partners = $this->customerGroup('همکاران', overrides: ['agent_pricing' => true]);
        $wholesale = $this->customerGroup('عمده', [$staff], ['agent_pricing' => true]);
        $note = 'این حساب خود شماست؛ گذاشتنش در گروه «همکار» با مدیر دیگری است.';

        $this->send($this->tap(Nav::to('usg', 'show', $staff->id), chat: self::STAFF));
        self::assertContains(Nav::to('usg', 'sw', $staff->id, $vip->id, 1), $this->window(), 'a plain group still switches');
        self::assertNotContains(Nav::to('usg', 'sw', $staff->id, $partners->id, 1), $this->window(), 'a «همکار» group they are not in: another admin\'s');
        self::assertContains(Nav::to('usg', 'sw', $staff->id, $wholesale->id, 0), $this->window(), 'one they are in: leaving it stays');
        self::assertStringContainsString('۳ گروه · عضو ۱', $this->screen(), 'every group counted');
        self::assertStringContainsString($note, $this->screen());

        $this->send($this->tap(Nav::to('usg', 'sw', $staff->id, $vip->id, 1), chat: self::STAFF));
        self::assertTrue($staff->groups()->whereKey($vip->id)->exists(), 'a switch kept is one the server takes');

        $this->send($this->tap(Nav::to('usg', 'show', $staff->id), chat: 7200));
        self::assertContains(Nav::to('usg', 'sw', $staff->id, $partners->id, 1), $this->window(), 'another admin puts them in it');
        self::assertStringNotContainsString('خود شماست', $this->screen());

        $this->send($this->tap(Nav::to('usg', 'show', $ali->id), chat: self::STAFF));
        self::assertContains(Nav::to('usg', 'sw', $ali->id, $partners->id, 1), $this->window(), 'another customer\'s, every switch');
        self::assertStringNotContainsString('خود شماست', $this->screen());
    }

    public function testAGroupAnAdminIsInIsMadeAPartnerGroupByAnotherAdmin(): void
    {
        $staff = $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Staff', 'role' => UserRole::Admin]);
        $this->customer(['telegram_id' => 7200, 'first_name' => 'Reza', 'role' => UserRole::Admin]);
        $vip = $this->customerGroup('VIP', [$staff]);
        $gold = $this->customerGroup('طلایی');
        $partners = $this->customerGroup('همکاران', [$staff], ['agent_pricing' => true]);
        $note = 'حساب خود شما در این گروه است؛ روشن کردن «همکار» آن با مدیر دیگری است.';

        $this->send($this->tap(Nav::to('ugv', 'show', $vip->id), chat: self::STAFF));
        self::assertNotContains(Nav::to('ugv', 'ap', $vip->id, 1), $this->window(), '«همکار» on: another admin\'s');
        self::assertContains(Nav::to('ugv', 'name', $vip->id), $this->window(), 'renaming stays');
        self::assertContains(Nav::to('ugv', 'del', $vip->id), $this->window(), 'and deleting');
        self::assertStringContainsString($note, $this->screen());

        $this->send($this->tap(Nav::to('ugv', 'show', $gold->id), chat: self::STAFF));
        self::assertContains(Nav::to('ugv', 'ap', $gold->id, 1), $this->window(), 'a group they are not in');
        self::assertStringNotContainsString('خود شماست', $this->screen());
        $this->send($this->tap(Nav::to('ugv', 'show', $partners->id), chat: self::STAFF));
        self::assertContains(Nav::to('ugv', 'ap', $partners->id, 0), $this->window(), 'a «همکار» one they are in is still switched off');
        self::assertStringNotContainsString('خود شماست', $this->screen());

        $this->send($this->tap(Nav::to('ugv', 'name', $vip->id), chat: self::STAFF));
        $this->send($this->message('VIP ویژه', chat: self::STAFF));
        self::assertSame(['VIP ویژه', false], [$vip->refresh()->name, $vip->agent_pricing], 'renamed by them, «همکار» left off');

        $this->send($this->tap(Nav::to('ugv', 'show', $vip->id), chat: 7200));
        self::assertContains(Nav::to('ugv', 'ap', $vip->id, 1), $this->window(), 'another admin switches it');
        self::assertStringNotContainsString('خود شماست', $this->screen());
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
