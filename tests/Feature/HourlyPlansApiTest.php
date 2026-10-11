<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * A plan's term through the APIs: saved as `duration_hours` or (older clients) `duration_days`, hours winning; read back in
 * both, `duration_days` rounded up so a six-hour term never reads as "never ends".
 */
final class HourlyPlansApiTest extends HttpTestCase
{
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->server = $this->sellingServer('Berlin');
    }

    public function testAPlanIsMadeInHoursOrInDaysAndReadInBoth(): void
    {
        $this->loginAsAdmin();

        $hourly = $this->decode($this->postJson('/api/admin/plans', $this->input(['duration_hours' => '۶'])))['plan'];
        self::assertSame([6, 1], [$hourly['duration_hours'], $hourly['duration_days']], 'Persian digits taken; a part of a day is a day');

        $daily = $this->decode($this->postJson('/api/admin/plans', $this->input(['duration_days' => '30'])))['plan'];
        self::assertSame([720, 30], [$daily['duration_hours'], $daily['duration_days']], 'the days of before hourly plans');

        $both = $this->decode($this->postJson('/api/admin/plans', $this->input(['duration_hours' => '12', 'duration_days' => '30'])))['plan'];
        self::assertSame(12, $both['duration_hours'], 'hours win');

        $edited = $this->decode($this->putJson("/api/admin/plans/{$daily['id']}", $this->input(['duration_hours' => '30'])))['plan'];
        self::assertSame([30, 2], [$edited['duration_hours'], $edited['duration_days']]);
        self::assertSame(30, Plan::query()->findOrFail($daily['id'])->duration_hours);
    }

    public function testATermIsRefusedUnderTheFieldItWasSentAs(): void
    {
        $this->loginAsAdmin();

        $none = $this->postJson('/api/admin/plans', $this->input());
        self::assertSame(422, $none->getStatusCode());
        self::assertSame(['duration_hours'], array_keys($this->decode($none)['errors']), 'a term is required, in one form or the other');

        $hours = $this->decode($this->postJson('/api/admin/plans', $this->input(['duration_hours' => '87601'])))['errors'];
        self::assertSame(['duration_hours' => ['مدت اعتبار باید عددی بین 0 (بدون محدودیت) تا 87600 ساعت (3650 روز) باشد.']], $hours);

        $days = $this->decode($this->postJson('/api/admin/plans', $this->input(['duration_days' => '3651'])))['errors'];
        self::assertSame(['duration_days' => ['مدت اعتبار باید عددی بین 0 (بدون محدودیت) تا 3650 روز باشد.']], $days);
        self::assertSame(0, Plan::query()->count());
    }

    public function testAnHourlyServiceIsReadInHoursAndInWholeDays(): void
    {
        $plan = $this->plan(['name' => 'شش‌ساعته', 'duration_hours' => 6], $this->server);
        $customer = $this->customer(['username' => 'ali']);
        $service = $this->subscription($customer, $plan, $this->server, 'ali_1', ['starts_at' => null, 'expires_at' => null]);
        $website = $this->website();

        $offered = $this->decode($this->get("/api/store/v1/{$website->key}/plans"))['groups'][0]['plans'][0];
        self::assertSame([6, 1], [$offered['duration_hours'], $offered['duration_days']], 'the website\'s catalogue');

        $this->bearer($this->customerSession($customer));
        $term = $this->decode($this->get("/api/store/v1/{$website->key}/subscriptions/{$service->id}"))['subscription']['term'];
        self::assertSame(['duration_days' => 1, 'duration_hours' => 6, 'starts_at' => null, 'expires_at' => null, 'awaits_first_use' => true], $term);

        $this->bearer(null);
        $this->loginAsAdmin();
        $row = $this->decode($this->get('/api/admin/subscriptions'))['subscriptions'][0];
        self::assertSame([6, 1], [$row['duration_hours'], $row['duration_days']], 'the panels\' list');
    }

    /**
     * A plan of the admin's form, its term left to `$overrides`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'پلن ' . (Plan::query()->count() + 1), 'price' => '50000', 'traffic_gb' => '10', 'ip_limit' => '1', 'is_active' => true,
            'servers' => [['server_id' => $this->server->id, 'all_inbounds' => true]],
        ];
    }
}
