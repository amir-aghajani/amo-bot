<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Modules\Bots\CurrentBot;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Store\Models\Website;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/** The timed sales running now, for a banner: the soonest to end first, those without an end last; read by anyone. */
final class StoreSalesTest extends HttpTestCase
{
    private Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->website = $this->website();
    }

    public function testTheRunningSalesSoonestToEndFirst(): void
    {
        $plan = $this->plan();
        $open = $this->sale('فروش همیشگی', 10);
        $later = $this->sale('فروش پاییز', 15, ['ends_at' => now()->addDays(10)]);
        $soon = $this->sale('فروش ویژه نوروز', 20, ['ends_at' => now()->addHours(5), 'plan_ids' => [$plan->id], 'order_types' => ['renewal']]);
        $this->sale('تمام‌شده', 30, ['ends_at' => now()->subMinute()]);
        $this->sale('هنوز نه', 30, ['starts_at' => now()->addDay()]);
        $this->sale('خاموش', 30, ['enabled' => false]);

        self::assertSame(['sales' => [
            ['id' => $soon->id, 'name' => 'فروش ویژه نوروز', 'percent' => 20, 'ends_at' => '2026-10-08T17:00:00+00:00', 'plan_ids' => [$plan->id], 'order_types' => ['renewal']],
            ['id' => $later->id, 'name' => 'فروش پاییز', 'percent' => 15, 'ends_at' => '2026-10-18T12:00:00+00:00', 'plan_ids' => null, 'order_types' => null],
            ['id' => $open->id, 'name' => 'فروش همیشگی', 'percent' => 10, 'ends_at' => null, 'plan_ids' => null, 'order_types' => null],
        ]], $this->decode($this->get($this->storeApi($this->website, '/sales'))), 'no token needed; ended, scheduled and switched-off sales left out');
    }

    public function testNoSaleIsAnEmptyList(): void
    {
        self::assertSame(['sales' => []], $this->decode($this->get($this->storeApi($this->website, '/sales'))));
    }

    public function testAnAgentsShopHasItsOwnSales(): void
    {
        $this->sale('فروش فروشگاه اصلی', 20);
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Sale => $this->sale('فروش نماینده', 5));
        $website = CurrentBot::run($bot, fn(): Website => $this->website());

        self::assertSame([$theirs->id], array_column($this->decode($this->get($this->storeApi($website, '/sales')))['sales'], 'id'));
    }
}
