<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\ExtrasHandler;
use App\Modules\Telegram\Handlers\MenuHandler;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A sale of some plan categories as the bot shows it: each plan of a category on sale at its sale price on its button
 * and in its details (saying why), every other plan at its own; and a service's extras at the sale when its plan is
 * filed under such a category — as their checkout charges them (Pricing).
 */
final class BotCategorySalesTest extends BotTestCase
{
    private Server $server;
    private PlanCategory $monthly;
    private PlanCategory $weekly;
    private Plan $plan;
    private Plan $weeklyPlan;
    private Plan $loose;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->withoutQr();
        $this->server = $this->sellingServer();
        $this->monthly = $this->category(['name' => 'ماهانه']);
        $this->weekly = $this->category(['name' => 'هفتگی']);
        $this->plan = $this->plan(['name' => 'یک‌ماهه', 'price' => '100000.00', 'category_id' => $this->monthly->id], $this->server);
        $this->weeklyPlan = $this->plan(['name' => 'هفتگی', 'price' => '100000.00', 'category_id' => $this->weekly->id], $this->server);
        $this->loose = $this->plan(['name' => 'بی‌دسته', 'price' => '100000.00'], $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testACategorysPlansAreListedAtItsSaleAndTheirDetailsSayWhy(): void
    {
        $this->sale('فروش ماهانه', 20, ['category_ids' => [$this->monthly->id]]);

        $this->send($this->tap(MainMenu::PLANS));
        $this->send($this->tap(MenuHandler::categoryCallback($this->monthly->id)));
        self::assertSame($this->planButton($this->plan, '80000'), $this->inlineKeyboard(0)[0][0]['text'], 'a plan of the category on sale');
        $this->send($this->tap(MenuHandler::categoryCallback($this->weekly->id)));
        self::assertSame($this->planButton($this->weeklyPlan, '100000'), $this->inlineKeyboard(0)[0][0]['text'], 'a plan of another category');
        $this->send($this->tap(MenuHandler::categoryCallback(null)));
        self::assertSame($this->planButton($this->loose, '100000'), $this->inlineKeyboard(0)[0][0]['text'], 'a plan in no category');

        $this->send($this->tap(PurchaseHandler::planCallback($this->plan->id)));
        self::assertStringContainsString(self::text(BotText::PlanSale, ['discount' => 'فروش ماهانه ۲۰٪', 'regular' => Money::format('100000')]), $this->said()[0]);
        self::assertStringContainsString('<b>' . Money::format('80000') . '</b>', $this->said()[0]);

        $this->send($this->tap(PurchaseHandler::planCallback($this->weeklyPlan->id)));
        self::assertStringNotContainsString('فروش ماهانه', $this->said()[0], "nothing off another category's plan");
        self::assertStringContainsString('<b>' . Money::format('100000') . '</b>', $this->said()[0]);
    }

    public function testAServicesExtrasAreOfferedAtTheSaleOfItsPlansCategory(): void
    {
        $this->extrasOnSale();
        // 18 GB of their 30 used, 12 left.
        $monthly = $this->serviceOn($this->plan, 'ali_1');
        $loose = $this->serviceOn($this->loose, 'ali_2');
        $sale = $this->sale('فروش هفتگی', 20, ['category_ids' => [$this->weekly->id]]);

        $this->send($this->tap(ExtrasHandler::screenCallback($monthly->id, ExtraKind::Traffic)));
        self::assertSame([$this->option($monthly, 50, '100000'), $this->option($monthly, 10, '18000')], $this->inlineKeyboard(0)[0], 'a sale of another category: nothing off');

        $sale->forceFill(['name' => 'فروش ماهانه', 'category_ids' => [$this->monthly->id]])->save();

        $this->send($this->tap(ExtrasHandler::screenCallback($monthly->id, ExtraKind::Traffic)));
        self::assertSame([$this->option($monthly, 50, '80000'), $this->option($monthly, 10, '14400')], $this->inlineKeyboard(0)[0], "20% off each: the service's plan is filed under the category");
        self::assertStringContainsString(self::text(BotText::ExtraTrafficTyped, ['least' => Persian::number(1), 'most' => Persian::number(100), 'unit_price' => Money::format('1600')]), $this->said()[0], 'and off the price of a GB typed');
        self::assertSame('14400.00', $this->service(Pricing::class)->extra($this->ali, $monthly->refresh(), ExtraKind::Traffic, '18000')->amount(), 'what its button says is what its checkout charges');

        $this->send($this->tap(ExtrasHandler::screenCallback($loose->id, ExtraKind::Traffic)));
        self::assertSame([$this->option($loose, 50, '100000'), $this->option($loose, 10, '18000')], $this->inlineKeyboard(0)[0], 'a service on a plan in no category: nothing off');
    }

    /** A service of Ali's on the plan, its traffic 18 GB used, as its panel has it. */
    private function serviceOn(Plan $plan, string $name): Subscription
    {
        $subscription = $this->subscription($this->ali, $plan, $this->server, $name, ['download_bytes' => Traffic::bytesOfGb(18)]);
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    private function planButton(Plan $plan, string $price): string
    {
        return self::text(BotText::PlanButton, [
            'plan' => $plan->name,
            'traffic' => Messages::traffic($plan->trafficBytes()),
            'duration' => Messages::duration($plan->duration_hours),
            'price' => Money::format($price),
        ]);
    }

    /**
     * The button of that much extra traffic for the service at `$price`.
     *
     * @return array{text: string, callback_data: string}
     */
    private function option(Subscription $subscription, int $quantity, string $price): array
    {
        return [
            'text' => self::text(BotText::ExtraOption, ['extra' => ExtraKind::Traffic->words($quantity), 'amount' => Money::format($price)]),
            'callback_data' => ExtrasHandler::checkoutCallback($subscription->id, ExtraKind::Traffic, $quantity),
        ];
    }
}
