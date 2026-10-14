<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\RenewalRefusal;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\CustomerRenewal;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * «آستانه مجاز تمدید» and «روش‌های تمدید» as CustomerRenewal keeps them for every door: a running service may be renewed
 * once its deadline is within the window's days, or once at most the window's percent of its traffic is left — any
 * time with both off, an ended one always, one without a deadline by its traffic alone —, with words for from when; and
 * the preview is by a method the shop offers, the first when none is asked.
 */
final class RenewalWindowTest extends DatabaseTestCase
{
    private Server $server;
    private Plan $plan;
    private User $ali;
    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->ali = $this->customer(['username' => 'ali']);
    }

    public function testWithNoWindowAServiceMayBeRenewedAnyTime(): void
    {
        $service = $this->service30(daysLeft: 29, usedGb: 1);

        self::assertTrue($this->renewal()->window($service)->open);
        self::assertSame('', $this->renewal()->window($service)->when());
        self::assertNotNull($this->renewal()->preview($service));
    }

    public function testADaysWindowOpensThatManyDaysBeforeTheDeadline(): void
    {
        $this->window(days: 7);
        $early = $this->service30(daysLeft: 10, usedGb: 29);

        $window = $this->renewal()->window($early);
        self::assertSame([false, '2026-10-15 12:00:00', null, null], [$window->open, $window->opensAt?->format('Y-m-d H:i:s'), $window->daysBefore, $window->percent], 'its deadline less seven days; the traffic counts for nothing');
        self::assertSame('از ' . Persian::date(Carbon::parse('2026-10-15 12:00:00'), withTime: true), $window->when());
        self::assertNull($this->renewal()->preview($early), 'no checkout before then');

        self::assertTrue($this->renewal()->window($this->service30(daysLeft: 7))->open, 'seven days before');
        self::assertTrue($this->renewal()->window($this->service30(daysLeft: 1))->open);
        Carbon::setTestNow('2026-10-15 12:00:00');
        self::assertTrue($this->renewal()->window($early)->open, 'open from the moment it said');
    }

    public function testAPercentWindowOpensOnceSoLittleTrafficIsLeft(): void
    {
        $this->window(percent: 20);
        $plenty = $this->service30(daysLeft: 1, usedGb: 20);

        $window = $this->renewal()->window($plenty);
        self::assertSame([false, null, null, 20], [$window->open, $window->opensAt, $window->daysBefore, $window->percent], 'ten of thirty left; its deadline counts for nothing');
        self::assertSame('وقتی ٪۲۰ از حجم یا کمتر مانده باشد', $window->when());

        self::assertTrue($this->renewal()->window($this->service30(daysLeft: 25, usedGb: 24))->open, 'six of thirty: twenty percent');
        $unlimited = $this->renewal()->window($this->service30(daysLeft: 1, overrides: ['traffic_limit_bytes' => 0]));
        self::assertSame([false, null, 'پس از پایان آن'], [$unlimited->open, $unlimited->percent, $unlimited->when()], 'unlimited traffic never runs low: only its end opens it');
    }

    public function testEitherRuleOpensItAndTheWordsSayBoth(): void
    {
        $this->window(days: 7, percent: 20);

        $closed = $this->renewal()->window($this->service30(daysLeft: 10, usedGb: 1));
        self::assertSame('از ' . Persian::date(Carbon::parse('2026-10-15 12:00:00'), withTime: true) . ' یا وقتی ٪۲۰ از حجم یا کمتر مانده باشد', $closed->when());
        self::assertTrue($this->renewal()->window($this->service30(daysLeft: 5, usedGb: 1))->open, 'by its deadline');
        self::assertTrue($this->renewal()->window($this->service30(daysLeft: 25, usedGb: 27))->open, 'by its traffic');
    }

    public function testAnEndedServiceMayAlwaysBeRenewed(): void
    {
        $this->window(days: 7, percent: 20);

        foreach ([
            'ended' => ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()],
            'past its deadline before a sync said so' => ['expires_at' => now()->subMinute()],
            'out of traffic before a sync said so' => ['download_bytes' => Traffic::bytesOfGb(30)],
            'ended by traffic, its days still running' => ['status' => SubscriptionStatus::Expired, 'download_bytes' => Traffic::bytesOfGb(30)],
        ] as $case => $overrides) {
            $service = $this->service30(daysLeft: 20, overrides: $overrides);

            self::assertTrue($this->renewal()->window($service)->open, $case);
            self::assertNotNull($this->renewal()->preview($service), $case);
        }
    }

    public function testAServiceWithoutADeadlineIsLetInByItsTrafficAlone(): void
    {
        $this->window(days: 7);
        $waiting = $this->service30(daysLeft: null);
        $never = $this->service30(daysLeft: null, overrides: ['duration_hours' => 0]);

        $window = $this->renewal()->window($waiting);
        self::assertSame([false, null, 7, null], [$window->open, $window->opensAt, $window->daysBefore, $window->percent], 'its clock not started: no deadline to come near yet');
        self::assertSame('از ۷ روز مانده به پایان آن', $window->when());
        self::assertSame('پس از پایان آن', $this->renewal()->window($never)->when(), 'one that never ends');

        $this->window(days: 7, percent: 50);
        self::assertTrue($this->renewal()->window($this->service30(daysLeft: null, usedGb: 20))->open, 'ten of thirty left lets it in');
        self::assertFalse($this->renewal()->window($never)->open, 'nothing used');
    }

    public function testThePreviewIsByTheMethodAskedAmongThoseTheShopOffers(): void
    {
        $this->botSettings('renewal', ['renew_extend' => true, 'renew_reset_traffic' => true, 'renew_reset_time' => false, 'renew_default_method' => 'reset_traffic']);
        $service = $this->service30(daysLeft: 5, usedGb: 20);

        $first = $this->renewal()->preview($service);
        $reset = $this->renewal()->preview($service, method: RenewalMethod::ResetTraffic);
        self::assertNotNull($first);
        self::assertNotNull($reset);

        self::assertSame([RenewalMethod::Extend, [RenewalMethod::Extend, RenewalMethod::ResetTraffic]], [$first->method, $first->methods], 'the first offered, not automatic renewal\'s default');
        self::assertSame(Traffic::bytesOfGb(60), $first->after->traffic_limit_bytes);
        self::assertSame([RenewalMethod::ResetTraffic, Traffic::bytesOfGb(30), 0], [$reset->method, $reset->after->traffic_limit_bytes, $reset->after->usedBytes()], 'counted afresh');
        self::assertSame($first->quote->amount(), $reset->quote->amount(), 'the same price by either');

        self::assertNull($this->renewal()->preview($service, method: RenewalMethod::ResetTime), 'a method the shop does not offer');
        self::assertSame(RenewalRefusal::MethodNotOffered, $this->renewal()->refusal($service, method: RenewalMethod::ResetTime), 'and why');
    }

    /** The renewal group's window: `$days` before the deadline, `$percent` of the traffic left (0: off). */
    private function window(int $days = 0, int $percent = 0): void
    {
        $this->botSettings('renewal', ['renew_window_days' => $days, 'renew_window_percent' => $percent]);
    }

    /**
     * A 30-day, 30 GB service of ali's with `$usedGb` used, `$daysLeft` to go — null: its clock not started.
     *
     * @param array<string, mixed> $overrides
     */
    private function service30(?int $daysLeft, int $usedGb = 0, array $overrides = []): Subscription
    {
        return $this->subscription($this->ali, $this->plan, $this->server, 'ali_' . ++$this->made, $overrides + [
            'download_bytes' => Traffic::bytesOfGb($usedGb),
            'starts_at' => $daysLeft === null ? null : now()->addDays($daysLeft - 30),
            'expires_at' => $daysLeft === null ? null : now()->addDays($daysLeft),
        ]);
    }

    private function renewal(): CustomerRenewal
    {
        return $this->service(CustomerRenewal::class);
    }
}
