<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Discounts\Models\DiscountCode;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Discounts\Services\DiscountSettings;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Telegram\Admin\Sections\Marketing\Form\Moment;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use Tests\BotTestCase;

/**
 * «🏷 کدهای تخفیف» in the bot's management (Sections\Marketing\Discounts): a code made step by step — typed answers, a
 * choice, several kinds ticked, a Jalali day — then saved at once through the panel's route, its refusals marked on the
 * summary; a code switched, edited and deleted (one an order keeps refused up front); the settings card over its two
 * groups; and the section kept from the shop's admins.
 */
final class MarketingDiscountsTest extends BotTestCase
{
    private const OWNER = 7001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
    }

    public function testTheOwnerMakesACodeStepByStepAndSavesItOnce(): void
    {
        $this->owner();
        $this->send($this->message('/admin', chat: self::OWNER));
        $this->send($this->tap(Nav::to('hm'), chat: self::OWNER));
        self::assertContains(Nav::to('dc'), $this->buttons(), 'the marketing menu leads to the codes');

        $this->send($this->tap(Nav::to('dc'), chat: self::OWNER));
        self::assertStringContainsString('کدهای تخفیف', $this->said()[0]);
        self::assertContains(Nav::to('dcf', 'new'), $this->buttons());

        $this->send($this->tap(Nav::to('dcf', 'new'), chat: self::OWNER));
        self::assertStringContainsString('✍️ کد', $this->said()[0]);
        self::assertStringContainsString('قدم ۱ از', $this->said()[0]);
        self::assertContains(Nav::to('dcf', 'q', 0, 0), $this->buttons(), 'a random code at a tap');

        $this->send($this->message('nowruz', chat: self::OWNER));
        self::assertStringContainsString('نوع تخفیف', $this->lastSaid());
        $this->send($this->tap(Nav::to('dcf', 'pick', 1, 0), chat: self::OWNER));
        self::assertStringContainsString('درصد تخفیف', $this->said()[0]);

        $this->send($this->message('۲۰۰', chat: self::OWNER));
        self::assertStringContainsString('عددی بین ۱ تا ۱۰۰', $this->lastSaid(), 'out of range: asked again');
        $this->send($this->message('۲۰', chat: self::OWNER));
        self::assertStringContainsString('سقف تخفیف', $this->lastSaid());
        $this->send($this->message('۵۰ هزار تومان', chat: self::OWNER));
        self::assertStringContainsString('برای چه سفارش‌هایی', $this->lastSaid());

        $this->send($this->tap(Nav::to('dcf', 'mt', 5, 0), chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcf', 'mt', 5, 1), chat: self::OWNER));
        self::assertStringContainsString('✅ تمدید', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        $this->send($this->tap(Nav::to('dcf', 'done', 5), chat: self::OWNER));
        self::assertStringContainsString('شروع', $this->said()[0]);
        $this->send($this->tap(Nav::to('dcf', 'skip', 6), chat: self::OWNER));
        $this->send($this->message('1406/01/15 18', chat: self::OWNER));
        self::assertStringContainsString('سقف کل استفاده', $this->lastSaid());
        $this->send($this->tap(Nav::to('dcf', 'skip', 8), chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcf', 'q', 9, 0), chat: self::OWNER));

        $summary = $this->said()[0];
        self::assertStringContainsString('NOWRUZ', $summary);
        self::assertStringContainsString('۲۰٪', $summary);
        self::assertStringContainsString('خرید، تمدید', $summary);
        self::assertStringContainsString('یک‌بار مصرف', $summary);
        self::assertContains(Nav::to('dcf', 'ask', 10), $this->buttons(), 'the plans, from the summary alone');
        self::assertSame(0, DiscountCode::query()->count(), 'nothing saved before «ساختن»');

        $this->send($this->tap(Nav::to('dcf', 'save'), chat: self::OWNER));
        $code = DiscountCode::query()->sole();
        self::assertSame(['NOWRUZ', 'percent', '20.00', '50000.00', ['purchase', 'renewal'], 1], [$code->code, $code->type->value, $code->value, $code->max_discount, $code->order_types, $code->per_user]);
        self::assertSame('2027-04-04 18:00', LocalTime::of($code->ends_at ?? self::fail('no end'))->format('Y-m-d H:i'));
        self::assertStringContainsString('ساخته شد', (string) $this->popup());
        self::assertContains(Nav::to('dcv', 'off', $code->id), $this->buttons(), 'the new code\'s own screen');
    }

    public function testARefusedSaveIsMarkedBesideItsField(): void
    {
        $this->owner();
        $this->send($this->message('/admin', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcf', 'new'), chat: self::OWNER));

        $this->send($this->tap(Nav::to('dcf', 'save'), chat: self::OWNER));

        self::assertStringContainsString('کد را بنویسید', (string) $this->popup());
        self::assertStringContainsString('⚠️', $this->said()[0], 'the summary, the refusal under the field');
        self::assertContains(Nav::to('dcf', 'save'), $this->buttons());
        self::assertSame(0, DiscountCode::query()->count());
    }

    public function testACodeIsSwitchedEditedAndDeleted(): void
    {
        $this->owner();
        $code = $this->code('YALDA');
        $this->send($this->message('/admin', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dc'), chat: self::OWNER));
        self::assertContains(Nav::to('dcv', 'show', $code->id), $this->buttons());

        $this->send($this->tap(Nav::to('dcv', 'show', $code->id), chat: self::OWNER));
        self::assertStringContainsString('YALDA', $this->said()[0]);
        $this->send($this->tap(Nav::to('dcv', 'off', $code->id), chat: self::OWNER));
        self::assertFalse($code->refresh()->enabled, 'switched off through the panel\'s route');

        $this->send($this->tap(Nav::to('dcf', 'edit', $code->id), chat: self::OWNER));
        self::assertStringContainsString('ویرایش کد', $this->said()[0]);
        $this->send($this->tap(Nav::to('dcf', 'ask', 2), chat: self::OWNER));
        $this->send($this->message('35', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcf', 'save'), chat: self::OWNER));
        self::assertSame('35.00', $code->refresh()->value);
        self::assertFalse($code->enabled, 'the rest of the form as it was');

        $this->send($this->tap(Nav::to('dcv', 'del', $code->id), chat: self::OWNER));
        self::assertContains(Nav::to('dcv', 'gone', $code->id), $this->buttons(), 'asked first');
        $this->send($this->tap(Nav::to('dcv', 'gone', $code->id), chat: self::OWNER));
        self::assertNull(DiscountCode::query()->find($code->id));
    }

    public function testAnEditStartsFromTheCodeAsThePanelHasItNow(): void
    {
        $this->owner();
        $code = $this->code('SPRING');
        $this->send($this->message('/admin', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dc'), chat: self::OWNER));
        // Changed on the panel after the list was read: renamed, capped, noted.
        $code->forceFill(['code' => 'SUMMER', 'max_uses' => 50, 'note' => 'از پنل'])->save();

        $this->send($this->tap(Nav::to('dcf', 'edit', $code->id), chat: self::OWNER));
        self::assertStringContainsString('SUMMER', $this->said()[0], 'the code read afresh, not the list\'s kept row');
        self::assertStringContainsString('از پنل', $this->said()[0]);
        self::assertStringContainsString('💾 ذخیره تغییرات', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        self::assertContains(Nav::to('dcf', 'pick', 15, 1), $this->buttons(), 'the switch sets "off", the value its tap gives');
        $this->send($this->tap(Nav::to('dcf', 'pick', 15, 1), chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcf', 'pick', 15, 1), chat: self::OWNER));
        self::assertContains(Nav::to('dcf', 'pick', 15, 0), $this->buttons(), 'tapped twice: still off');
        $this->send($this->tap(Nav::to('dcf', 'ask', 2), chat: self::OWNER));
        $this->send($this->message('15', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcf', 'save'), chat: self::OWNER));

        $code->refresh();
        self::assertSame(['SUMMER', '15.00', 50, 'از پنل', false], [$code->code, $code->value, $code->max_uses, $code->note, $code->enabled], 'the panel\'s changes kept');
    }

    public function testACodeAnOrderKeepsIsNotDeleted(): void
    {
        $this->owner();
        $code = $this->code('KEPT');
        $buyer = $this->customer(['telegram_id' => 7500]);
        $server = $this->fakeServer();
        $this->purchaseOrder($buyer, $this->plan(on: $server), $server, ['discount_code_id' => $code->id, 'status' => OrderStatus::Paid]);
        $this->send($this->message('/admin', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dc'), chat: self::OWNER));

        $this->send($this->tap(Nav::to('dcv', 'del', $code->id), chat: self::OWNER));

        self::assertStringContainsString('برای سابقه می‌ماند', (string) $this->popup());
        self::assertNotContains(Nav::to('dcv', 'gone', $code->id), $this->buttons());
        self::assertNotNull(DiscountCode::query()->find($code->id));
    }

    public function testTheSettingsCardSavesEachFieldWithItsGroup(): void
    {
        $this->owner();
        $this->send($this->message('/admin', chat: self::OWNER));
        $this->send($this->tap(Nav::to('dcs'), chat: self::OWNER));
        self::assertStringContainsString('تنظیمات تخفیف‌ها', $this->said()[0]);
        self::assertStringContainsString('سرویس دلخواه', $this->said()[0], 'the main shop\'s words');

        $this->send($this->tap(Nav::to('dcs', 'e', 0), chat: self::OWNER));
        $this->send($this->message('۳۰', chat: self::OWNER));
        self::assertSame(30, $this->service(DiscountSettings::class)->holdMinutes());

        $this->send($this->tap(Nav::to('dcs', 'e', 1), chat: self::OWNER));
        self::assertTrue($this->service(DiscountSettings::class)->agentsWithoutPerks());
        self::assertSame(30, $this->service(DiscountSettings::class)->holdMinutes(), 'the other group untouched');
    }

    public function testAShopsAdminHasNoMarketing(): void
    {
        $this->customer(['telegram_id' => 7100, 'role' => UserRole::Admin]);
        $this->code('STAFF');

        $this->send($this->message('/admin', chat: 7100));
        self::assertNotContains(Nav::to('hm'), $this->buttons());

        $this->send($this->tap(Nav::to('dc'), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');
        $this->send($this->tap(Nav::to('dcf', 'new'), chat: 7100));
        self::assertStringNotContainsString('✍️', $this->said()[0]);
    }

    public function testAnAgentManagesTheirOwnShopsCodes(): void
    {
        $bot = $this->agentBot();
        $this->sendTo($bot, $this->message('/admin', chat: self::AGENT_TELEGRAM_ID));
        $this->sendTo($bot, $this->tap(Nav::to('dcs'), chat: self::AGENT_TELEGRAM_ID));

        self::assertStringContainsString('تنظیمات تخفیف‌ها', $this->said()[0]);
        self::assertStringNotContainsString('سرویس دلخواه', $this->said()[0], 'an agent\'s shop has no custom builder');
    }

    public function testAJalaliDayIsReadAsTheShopsMoment(): void
    {
        self::assertSame('2026-10-12T00:00', Moment::parse('1405/07/20', 0));
        self::assertSame('2026-10-12T18:30', Moment::parse('۱۴۰۵/۰۷/۲۰ ساعت ۱۸:۳۰', 0));
        self::assertSame('2025-03-20T23:00', Moment::parse('1403/12/30', 23), 'a leap year\'s last day');
        self::assertNull(Moment::parse('1404/12/30', 0), 'not in a common year');
        self::assertNull(Moment::parse('1405/13/01', 0));
        self::assertNull(Moment::parse('فردا', 0));
    }

    /** The last thing said: a question asked again over the window that was. */
    private function lastSaid(): string
    {
        $said = $this->said();

        return (string) end($said);
    }

    /** @return list<string> The window's buttons' data. */
    private function buttons(): array
    {
        return $this->callbacks($this->buttonsCall());
    }

    private function code(string $text): DiscountCode
    {
        return $this->service(DiscountCodes::class)->create(['code' => $text, 'type' => 'percent', 'value' => 10, 'first_purchase_only' => false, 'enabled' => true]);
    }

    /** The account 7001 linked as the owner. */
    private function owner(): User
    {
        $owner = User::query()->where('telegram_id', self::OWNER)->first() ?? $this->customer(['telegram_id' => self::OWNER, 'first_name' => 'Amir']);
        $this->linkOwner(self::OWNER);

        return $owner;
    }
}
