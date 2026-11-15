<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use Tests\BotTestCase;

/**
 * «🗂 دسته‌بندی‌ها» in the bot's management: the list, a category added by its name, renamed with a premium emoji, its
 * switch, its place, its plans, a delete asked first — and the catalogue's grant for the shop's admins.
 */
final class CatalogCategoriesTest extends BotTestCase
{
    private const OWNER = 7001;
    private const STAFF = 7100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerAddsRenamesAndSwitchesACategory(): void
    {
        $this->owner();

        $this->tapAs(Nav::to('ct'));
        self::assertStringContainsString('هنوز دسته‌ای تعریف نشده است', $this->said()[0]);
        $this->tapAs(Nav::to('ctv', 'new'));
        self::assertStringContainsString('نام دسته', $this->said()[0]);
        $this->sayAs('اقتصادی');

        $category = PlanCategory::query()->sole();
        self::assertSame(['اقتصادی', true], [$category->name, $category->is_active]);
        self::assertStringContainsString('دسته اضافه شد', $this->said()[0]);
        self::assertContains(Nav::to('ctv', 'tog', $category->id, 0), $this->offered(), 'the switch carries what it sets');

        $this->tapAs(Nav::to('ctv', 'name', $category->id));
        $this->send($this->message('⭐ ویژه', ['entities' => [['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => '5368324170671202286']]], chat: self::OWNER));
        $category->refresh();
        self::assertSame(['⭐ ویژه', '5368324170671202286'], [$category->name, $category->emoji_id], 'the premium emoji typed is the name\'s');

        $this->tapAs(Nav::to('ctv', 'emx', $category->id));
        self::assertNull($category->refresh()->emoji_id);
        self::assertSame('⭐ ویژه', $category->name, 'its plain emoji stays');

        $this->tapAs(Nav::to('ctv', 'tog', $category->id, 0));
        self::assertFalse($category->refresh()->is_active);
        self::assertSame('⏸ غیرفعال شد', $this->popup());
        self::assertStringContainsString('⏸ غیرفعال', $this->said()[0]);
        self::assertContains(Nav::to('ctv', 'tog', $category->id, 1), $this->offered());

        $this->tapAs(Nav::to('ctv', 'tog', $category->id, 0));
        self::assertFalse($category->refresh()->is_active, 'a second tap, or a stale window, sets it the same: never flipped back');
    }

    public function testAPlaceAPlansListAndADeleteAskedFirst(): void
    {
        $this->owner();
        $first = $this->category(['name' => 'ماهانه']);
        $second = $this->category(['name' => 'سه‌ماهه']);
        $plan = $this->plan(['category_id' => $second->id], $this->sellingServer());

        $this->tapAs(Nav::to('ctv', 'up', $second->id));
        self::assertSame(['سه‌ماهه', 'ماهانه'], PlanCategory::query()->oldest('sort')->pluck('name')->all());

        $this->tapAs(Nav::to('ctv', 'plans', $second->id));
        self::assertContains(Nav::to('plv', 'show', $plan->id), $this->callbacks(0), 'its plans, the list narrowed to it');

        $this->tapAs(Nav::to('ctv', 'del', $second->id));
        self::assertStringContainsString('این دسته حذف شود؟', $this->said()[0]);
        self::assertStringContainsString('پلن این دسته حذف نمی‌شود', $this->said()[0], 'what becomes of its plan');
        $this->tapAs(Nav::to('ctv', 'gone', $second->id));
        self::assertNull(PlanCategory::query()->find($second->id));
        self::assertNull($plan->refresh()->category_id, 'the plan stays, uncategorised');
        self::assertNotNull(PlanCategory::query()->find($first->id));
    }

    public function testAShopsAdminReadsTheCategoriesAndChangesThemOnlyWithTheGrant(): void
    {
        $this->customer(['telegram_id' => self::STAFF, 'role' => UserRole::Admin]);
        $category = $this->category();

        $this->send($this->tap(Nav::to('ct'), chat: self::STAFF));
        self::assertContains(Nav::to('ctv', 'show', $category->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('ctv', 'new'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('ctv', 'tog', $category->id, 0), chat: self::STAFF));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup());
        self::assertTrue($category->refresh()->is_active);

        $this->botSettings('bot_admins', ['bot_admins_grant_catalog' => true]);
        $this->send($this->tap(Nav::to('ctv', 'new'), chat: self::STAFF));
        $this->send($this->message('ماهانه', chat: self::STAFF));
        self::assertSame(2, PlanCategory::query()->count());
    }

    /**
     * The buttons the window shows now, whichever call put them there (after a typed answer, its deletion comes first).
     *
     * @return list<string>
     */
    private function offered(): array
    {
        $offered = [];
        foreach (array_keys($this->calls()) as $call) {
            $offered = [...$offered, ...$this->callbacks($call)];
        }

        return $offered;
    }

    private function tapAs(string $data): void
    {
        $this->send($this->tap($data, chat: self::OWNER));
    }

    private function sayAs(string $text): void
    {
        $this->send($this->message($text, chat: self::OWNER));
    }

    /** The account 7001 linked as the owner. */
    private function owner(): void
    {
        $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);
    }
}
