<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Rewards\Models\LotteryDraw;
use App\Modules\Rewards\Models\LotteryWinner;
use App\Modules\Rewards\Models\WheelPrize;
use App\Modules\Rewards\Models\WheelSpin;
use App\Modules\Rewards\Services\LotterySettings;
use App\Modules\Rewards\Services\WheelPrizes;
use App\Modules\Rewards\Services\WheelSettings;
use App\Modules\Telegram\Admin\Ui\Nav;
use Tests\BotTestCase;

/**
 * «🎡 گردونه شانس» and «🎲 قرعه‌کشی» in the bot's management (Sections\Marketing): a slice made as a wizard (a decimal of
 * GB), the chances, a slice moved on the wheel, the spins, the wheel's rules held while it is off; the draws and their
 * winners, and a plan prize picked with its plan in one save.
 */
final class MarketingWheelLotteryTest extends BotTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->owner();
        $this->send($this->message('/admin', chat: self::OWNER));
    }

    public function testASliceIsMadeMovedAndItsChanceSaid(): void
    {
        $this->tapAs(Nav::to('wh'));
        self::assertStringContainsString('گردونه شانس: ⏸ خاموش', $this->said()[0]);
        self::assertContains(Nav::to('whs'), $this->buttons(), 'the way to switch it on');

        $this->tapAs(Nav::to('whf', 'new'));
        $this->send($this->message('دو و نیم گیگ', chat: self::OWNER));
        $this->tapAs(Nav::to('whf', 'pick', 1, 1));
        self::assertStringContainsString('چند گیگابایت حجم بدهد؟', $this->said()[0]);
        $this->send($this->message('2.5', chat: self::OWNER));
        $this->tapAs(Nav::to('whf', 'q', 6, 0));
        self::assertStringContainsString('۲٫۵ گیگابایت', $this->said()[0]);
        $this->tapAs(Nav::to('whf', 'save'));
        $slice = WheelPrize::query()->sole();
        self::assertSame(['traffic', '2.50', 1, true], [$slice->kind->value, $slice->amount, $slice->weight, $slice->enabled]);

        $empty = $this->service(WheelPrizes::class)->create(['label' => 'پوچ', 'kind' => 'nothing', 'weight' => 3]);
        $this->tapAs(Nav::to('wh'));
        self::assertStringContainsString('شانس ۲۵٪', json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '', 'one weight of four');
        self::assertStringContainsString('شانس بردن در هر چرخش: <b>۲۵٪</b>', $this->said()[0], 'the odds of a spin');
        self::assertStringContainsString('پوچ: <b>۷۵٪</b>', $this->said()[0]);

        $this->tapAs(Nav::to('whv', 'show', $empty->id));
        self::assertContains(Nav::to('whv', 'up', $empty->id, 1), $this->buttons());
        self::assertNotContains(Nav::to('whv', 'down', $empty->id, 1), $this->buttons(), 'the last one');
        $this->tapAs(Nav::to('whv', 'up', $empty->id, 1));
        self::assertSame([$empty->id, $slice->id], WheelPrize::query()->orderBy('sort')->pluck('id')->all(), 'moved through the panel\'s reorder');
        self::assertStringContainsString('۱ از ۲', $this->said()[0]);

        $this->tapAs(Nav::to('whv', 'down', $slice->id, 0));
        self::assertSame([$empty->id, $slice->id], WheelPrize::query()->orderBy('sort')->pluck('id')->all(), 'a button from before the move moves nothing');
    }

    public function testTheOddsAreTheWheelsOwnWhenASliceIsOffItsPlanGoneOrItsWeightZero(): void
    {
        $prizes = $this->service(WheelPrizes::class);
        $wallet = $prizes->create(['label' => 'ده هزار', 'kind' => 'wallet', 'amount' => 10000, 'weight' => 1]);
        $free = $prizes->create(['label' => 'یک ماه رایگان', 'kind' => 'plan', 'plan_id' => $this->plan()->id, 'weight' => 2]);
        $prizes->create(['label' => 'پوچ', 'kind' => 'nothing', 'weight' => 1]);
        $prizes->create(['label' => 'پنج هزار', 'kind' => 'wallet', 'amount' => 5000, 'weight' => 5, 'enabled' => false]);
        // Its plan deleted (the slice's plan_id set null): the wheel never lands on it. A zero from an older row: drawn as one.
        $free->forceFill(['plan_id' => null])->save();
        $wallet->forceFill(['weight' => 0])->save();

        $this->tapAs(Nav::to('wh'));
        $keyboard = json_encode($this->inlineKeyboard($this->buttonsCall()), JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringContainsString('ده هزار · شانس ۵۰٪', $keyboard, 'one of two drawn weights: the off slice and the plan gone left out');
        self::assertStringContainsString('یک ماه رایگان · پلنش حذف شده', $keyboard);
        self::assertStringContainsString('پنج هزار · غیرفعال', $keyboard);
        self::assertStringContainsString('شانس بردن در هر چرخش: <b>۵۰٪</b>', $this->said()[0]);
        self::assertStringContainsString('پوچ: <b>۵۰٪</b>', $this->said()[0]);

        $this->tapAs(Nav::to('whv', 'show', $free->id));
        self::assertStringContainsString('شانس هر چرخش: <b>هیچ؛ پلن دیگری برایش انتخاب کنید</b>', $this->said()[0]);
    }

    public function testAShopOfManyPlansPicksTheDrawsPlanFromItsPages(): void
    {
        $plans = [];
        for ($n = 1; $n <= 45; $n++) {
            $plans[] = $this->plan(['name' => 'پلن ' . $n]);
        }
        $this->tapAs(Nav::to('lts', 'c', 3, 3));
        self::assertContains(Nav::to('lts', 'pp', $plans[39]->id), $this->buttons());
        self::assertNotContains(Nav::to('lts', 'pp', $plans[40]->id), $this->buttons(), 'forty plans a page');
        self::assertContains(Nav::to('lts', 'pl', 2), $this->buttons());

        $this->tapAs(Nav::to('lts', 'pl', 2));
        $this->tapAs(Nav::to('lts', 'pp', $plans[44]->id));
        self::assertSame($plans[44]->id, $this->service(LotterySettings::class)->prize()?->plan?->id);
    }

    public function testTheWheelsRulesWaitWhileItIsOff(): void
    {
        $this->tapAs(Nav::to('whs'));
        self::assertNotContains(Nav::to('whs', 'e', 1), $this->buttons(), 'held while the wheel is off');

        $this->tapAs(Nav::to('whs', 'e', 0));
        self::assertTrue($this->service(WheelSettings::class)->enabled());
        self::assertContains(Nav::to('whs', 'e', 1), $this->buttons());
        $this->tapAs(Nav::to('whs', 'e', 1));
        $this->send($this->message('۱۲', chat: self::OWNER));
        self::assertSame(12, $this->service(WheelSettings::class)->cooldownHours());
    }

    public function testTheSpinsAreListedWithWhatEachGave(): void
    {
        $customer = $this->customer(['telegram_id' => 7600, 'first_name' => 'Sara', 'username' => 'sara']);
        $spin = WheelSpin::query()->create(['user_id' => $customer->id, 'kind' => 'wallet', 'label' => '۱۰ هزار تومان', 'amount' => '10000']);

        $this->tapAs(Nav::to('whn'));
        self::assertContains(Nav::to('whx', 'show', $spin->id), $this->buttons());
        $this->tapAs(Nav::to('whx', 'show', $spin->id));
        self::assertStringContainsString("Sara · \u{2068}@sara\u{2069}", $this->said()[0], 'the handle isolated in the Persian line');
        self::assertStringContainsString('✅ داده شد', $this->said()[0]);

        $this->tapAs(Nav::to('whn', 'u', $customer->id + 1));
        self::assertStringContainsString('چرخشی نیست', $this->said()[0], 'another customer\'s');
    }

    public function testAPlanPrizeIsPickedWithItsPlanAndTheDrawsListed(): void
    {
        $plan = $this->plan(['name' => 'جایزه شب']);
        $this->tapAs(Nav::to('lts'));
        self::assertStringContainsString('نماینده‌ها شرکت داده نشوند', $this->said()[0], 'the main shop');

        $this->tapAs(Nav::to('lts', 'c', 3, 3));
        self::assertContains(Nav::to('lts', 'pp', $plan->id), $this->buttons(), 'no plan kept: picked first');
        $this->tapAs(Nav::to('lts', 'pp', $plan->id));
        self::assertSame($plan->id, $this->service(LotterySettings::class)->prize()?->plan?->id);
        self::assertStringContainsString('جایزه هر برنده: <b>سرویس رایگان «جایزه شب»</b>', $this->said()[0], 'the plan said with the prize');

        $winner = $this->customer(['telegram_id' => 7700, 'first_name' => 'Reza']);
        $draw = LotteryDraw::query()->create(['day' => '2026-11-14', 'entries' => 4, 'prize_kind' => 'wallet', 'prize_amount' => '50000']);
        LotteryWinner::query()->create(['lottery_draw_id' => $draw->id, 'user_id' => $winner->id, 'outcome' => '۵۰٬۰۰۰ تومان به کیف پول']);
        $this->tapAs(Nav::to('lt'));
        self::assertStringContainsString('قرعه‌کشی شبانه: ⏸ خاموش', $this->said()[0]);
        self::assertContains(Nav::to('ltv', 'show', $draw->id), $this->buttons());
        $this->tapAs(Nav::to('ltv', 'show', $draw->id));
        self::assertStringContainsString('۲۳ آبان ۱۴۰۵', $this->said()[0]);
        self::assertStringContainsString('Reza', $this->said()[0]);
    }

    /** @return list<string> The window's buttons' data. */
    private function buttons(): array
    {
        return $this->callbacks($this->buttonsCall());
    }
}
