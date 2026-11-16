<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Rewards\Models\CashbackRule;
use App\Modules\Rewards\Services\CashbackSettings;
use App\Modules\Telegram\Admin\Ui\Nav;
use Tests\BotTestCase;

/**
 * «🔥 فروش ویژه», «🏅 سطوح وفاداری» and «💸 کش‌بک» in the bot's management (Sections\Marketing): each made as a wizard and
 * saved once through the panel's route, switched, edited and deleted from its own screen, and the cashback switch.
 */
final class MarketingProgramsTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
        $this->send($this->message('/admin', chat: self::OWNER));
    }

    public function testASaleIsMadeForOnePlanSwitchedAndDeleted(): void
    {
        $plan = $this->plan(['name' => 'یک ماهه']);
        $this->tapAs(Nav::to('sl'));
        self::assertStringContainsString('هنوز فروش ویژه‌ای نساخته‌اید', $this->said()[0]);
        self::assertStringContainsString('<blockquote expandable>❔ فروش ویژه درصدی', $this->said()[0], 'the panel\'s ⓘ folded under the list');
        $this->tapAs(Nav::to('sl', 'info'));
        self::assertStringContainsString('هنوز فروش ویژه‌ای نساخته‌اید', $this->said()[0], 'an older window\'s «❔ راهنما»: the list, its help in it');

        $this->tapAs(Nav::to('slf', 'new'));
        $this->send($this->message('فروش ویژه نوروز', chat: self::OWNER));
        self::assertStringContainsString('چند درصد از قیمت کم شود؟', $this->said()[0]);
        self::assertStringContainsString('گام ۲ از ۶', $this->said()[0]);
        $this->tapAs(Nav::to('slf', 'q', 1, 1));
        $this->tapAs(Nav::to('slf', 'done', 2));
        self::assertStringContainsString('پلن‌ها', $this->said()[0]);
        self::assertStringContainsString('یک ماهه', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        $this->tapAs(Nav::to('slf', 'mt', 3, 0));
        $this->tapAs(Nav::to('slf', 'done', 3));
        $this->tapAs(Nav::to('slf', 'skip', 4));
        $this->tapAs(Nav::to('slf', 'q', 5, 1));
        self::assertStringContainsString('افزودن فروش ویژه', $this->said()[0], 'the review');

        self::assertContains(Nav::to('slf', 'pick', 6, 1), $this->buttons(), 'the switch sets "off"');
        $this->tapAs(Nav::to('slf', 'save'));
        $sale = Sale::query()->sole();
        self::assertSame(['فروش ویژه نوروز', 20, null, [$plan->id], true], [$sale->name, $sale->percent, $sale->order_types, $sale->plan_ids, $sale->enabled]);
        self::assertNotNull($sale->ends_at);

        $this->tapAs(Nav::to('slv', 'off', $sale->id));
        self::assertFalse($sale->refresh()->enabled);
        $this->tapAs(Nav::to('slv', 'del', $sale->id));
        self::assertStringContainsString('تخفیفشان را همان‌طور نگه می‌دارند', $this->said()[0]);
        $this->tapAs(Nav::to('slv', 'gone', $sale->id));
        self::assertSame(0, Sale::query()->count());
        self::assertContains(Nav::to('slf', 'new'), $this->buttons(), 'the list again');
    }

    public function testALoyaltyTierIsAddedEditedAndDeleted(): void
    {
        $this->tapAs(Nav::to('ly'));
        self::assertStringContainsString('هنوز سطحی تعریف نشده است', $this->said()[0]);

        $this->tapAs(Nav::to('lyf', 'new'));
        $this->send($this->message('طلایی', chat: self::OWNER));
        $this->send($this->message('۵۰۰ هزار تومان', chat: self::OWNER));
        $this->send($this->message('۵', chat: self::OWNER));
        $this->tapAs(Nav::to('lyf', 'q', 3, 0));
        $this->tapAs(Nav::to('lyf', 'save'));
        $tier = LoyaltyTier::query()->sole();
        self::assertSame(['طلایی', '500000.00', 5, 0], [$tier->name, $tier->min_spent, $tier->discount_percent, $tier->cashback_percent]);
        self::assertStringContainsString('از ۵۰۰٬۰۰۰ تومان', $this->said()[0]);

        $this->tapAs(Nav::to('lyf', 'edit', $tier->id));
        $this->tapAs(Nav::to('lyf', 'ask', 3));
        $this->send($this->message('۲', chat: self::OWNER));
        $this->tapAs(Nav::to('lyf', 'save'));
        self::assertSame(2, $tier->refresh()->cashback_percent);

        $this->tapAs(Nav::to('lyv', 'del', $tier->id));
        self::assertStringContainsString('هیچ مشتری‌ای روی این سطح نیست', $this->said()[0]);
        $this->tapAs(Nav::to('lyv', 'gone', $tier->id));
        self::assertSame(0, LoyaltyTier::query()->count());
    }

    public function testACashbackRuleNeedsAKindAndTakesAGroup(): void
    {
        $group = $this->customerGroup('VIP');
        $this->tapAs(Nav::to('cb'));
        self::assertStringContainsString('کش‌بک: ⏸ خاموش', $this->said()[0]);

        $this->tapAs(Nav::to('cbf', 'new'));
        $this->send($this->message('۱۰', chat: self::OWNER));
        self::assertStringContainsString('✅ خرید', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '', 'purchases and renewals to start with');
        $this->tapAs(Nav::to('cbf', 'mt', 1, 0));
        $this->tapAs(Nav::to('cbf', 'mt', 1, 1));
        $this->tapAs(Nav::to('cbf', 'done', 1));
        self::assertSame('دست‌کم یکی را انتخاب کنید.', $this->popup(), 'no kind: asked again');
        $this->tapAs(Nav::to('cbf', 'mt', 1, 2));
        $this->tapAs(Nav::to('cbf', 'done', 1));
        self::assertStringContainsString('VIP', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '');
        $this->tapAs(Nav::to('cbf', 'pick', 2, 1));
        $this->tapAs(Nav::to('cbf', 'pick', 3, 0));
        $this->tapAs(Nav::to('cbf', 'save'));

        $rule = CashbackRule::query()->sole();
        self::assertSame([10, ['extra_traffic'], $group->id, null, true], [$rule->percent, $rule->order_types, $rule->customer_group_id, $rule->payment_method_id, $rule->enabled]);
        $this->tapAs(Nav::to('cbv', 'off', $rule->id));
        self::assertFalse($rule->refresh()->enabled);

        // The card once opened from an older window's «🤖 تنظیمات ربات»: turned on from the list, its way back is the list.
        $this->tapAs(Nav::to('cbs', 'show', 'from.bs'));
        $this->tapAs(Nav::to('cb'));
        self::assertContains(Nav::to('cbs'), $this->buttons(), 'its settings card, from its own list');
        self::assertContains(Nav::to('cbs', 'on'), $this->buttons(), 'off: turned on at a tap, through its card');
        $this->tapAs(Nav::to('cbs', 'on'));
        self::assertTrue($this->service(CashbackSettings::class)->enabled());
        self::assertStringContainsString('تنظیمات کش‌بک', $this->said()[0], 'the card it was saved on');
        self::assertContains(Nav::to('cb'), $this->buttons(), 'its way back: the list it was turned on from');
        self::assertNotContains(Nav::to('bs'), $this->buttons());
        $this->tapAs(Nav::to('cb'));
        self::assertNotContains(Nav::to('cbs', 'on'), $this->buttons(), 'on: no turn-on button');
        self::assertStringContainsString('کش‌بک: 🟢 روشن', $this->said()[0]);
        $this->tapAs(Nav::to('cbs', 'on'));
        self::assertTrue($this->service(CashbackSettings::class)->enabled(), 'a second tap changes nothing');
    }

    /** @return list<string> The window's buttons' data. */
    private function buttons(): array
    {
        return $this->callbacks($this->buttonsCall());
    }
}
