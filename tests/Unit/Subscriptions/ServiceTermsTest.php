<?php

declare(strict_types=1);

namespace Tests\Unit\Subscriptions;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every rule of what a renewal, a grant and a renewed period give a service, from the row alone — the numbers
 * ProvisioningService then sends to the panel.
 */
final class ServiceTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app(); // the models' date casts
        Carbon::setTestNow('2026-09-20 12:00:00');
    }

    public function testARunningServiceGetsThePlansDaysOnItsDeadlineAndItsTrafficOnTop(): void
    {
        $terms = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(), carryTraffic: false);

        self::assertSame('2026-10-25 12:00:00', $terms->expiry->deadline()?->format('Y-m-d H:i:s'), 'the days left carry');
        self::assertSame(60, $terms->durationDays);
        self::assertSame(60 * Traffic::GIGABYTE, $terms->totalBytes, 'nothing taken away now');
        self::assertFalse($terms->resetCounters);
        self::assertSame(['2026-09-25 12:00:00', 30 * Traffic::GIGABYTE], [$terms->periodEndsAt?->format('Y-m-d H:i:s'), $terms->nextPeriodBytes], 'not carried: what this period leaves goes when it ends');

        $carried = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(), carryTraffic: true);
        self::assertSame(60 * Traffic::GIGABYTE, $carried->totalBytes);
        self::assertNull($carried->periodEndsAt, 'carried: nothing to take away later');

        $usedUp = ServiceTerms::renewal(self::row(usedGb: 30, daysLeft: 5), self::plan(), carryTraffic: false);
        self::assertNull($usedUp->periodEndsAt, 'nothing left of this period to take away');
    }

    public function testATermNotStartedGrowsAndStillWaitsForTheFirstConnection(): void
    {
        $terms = ServiceTerms::renewal(self::row(usedGb: 0, daysLeft: null), self::plan(), carryTraffic: false);

        self::assertNull($terms->expiry->deadline());
        self::assertSame(60 * 86400, $terms->expiry->pendingSeconds());
        self::assertSame(60 * Traffic::GIGABYTE, $terms->totalBytes);
        self::assertNull($terms->periodEndsAt);
    }

    public function testAnEndedServiceStartsAFreshCountAndANewTermAtItsNextConnection(): void
    {
        $ended = static fn(): Subscription => self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired);

        $terms = ServiceTerms::renewal($ended(), self::plan(), carryTraffic: false);
        self::assertSame(30 * 86400, $terms->expiry->pendingSeconds());
        self::assertSame(30, $terms->durationDays);
        self::assertSame(30 * Traffic::GIGABYTE, $terms->totalBytes, 'what was left went with the period');
        self::assertTrue($terms->resetCounters);
        self::assertNull($terms->periodEndsAt);

        self::assertSame(40 * Traffic::GIGABYTE, ServiceTerms::renewal($ended(), self::plan(), carryTraffic: true)->totalBytes, 'carried: the 10 left on top');

        $preview = $terms->preview($ended());
        self::assertSame([null, null, 30], [$preview->starts_at, $preview->expires_at, $preview->duration_days], 'shown before it is paid: no start until its next connection, as the panel will say');
        $running = self::row(usedGb: 20, daysLeft: 5);
        self::assertEquals($running->starts_at, ServiceTerms::renewal($running, self::plan(), carryTraffic: false)->preview($running)->starts_at, 'a running one keeps its start');
    }

    public function testAPlanWithoutATermOrWithoutAQuotaPassesThatOn(): void
    {
        $never = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['duration_days' => 0]), carryTraffic: false);
        self::assertSame([null, null, 0], [$never->expiry->deadline(), $never->expiry->pendingSeconds(), $never->durationDays]);

        $unlimited = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['traffic_gb' => 0]), carryTraffic: false);
        self::assertSame([0, null], [$unlimited->totalBytes, $unlimited->periodEndsAt], 'unlimited, nothing queued');

        $wasUnlimited = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5, limitGb: 0), self::plan(), carryTraffic: false);
        self::assertSame([30 * Traffic::GIGABYTE, true], [$wasUnlimited->totalBytes, $wasUnlimited->resetCounters], 'a limited plan on an unlimited service: a fresh count');
    }

    public function testARenewalQueuedBehindAnotherAddsToIt(): void
    {
        $service = self::row(usedGb: 20, daysLeft: 35, limitGb: 60);
        $service->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);

        $terms = ServiceTerms::renewal($service, self::plan(), carryTraffic: false);

        self::assertSame(90 * Traffic::GIGABYTE, $terms->totalBytes);
        self::assertSame(['2026-09-25 12:00:00', 60 * Traffic::GIGABYTE], [$terms->periodEndsAt?->format('Y-m-d H:i:s'), $terms->nextPeriodBytes], 'the first period still ends when it did');
    }

    public function testAGrantMovesTheTermAndAddsTrafficToWhatTheServiceHasNow(): void
    {
        $terms = ServiceTerms::grant(self::row(usedGb: 35, daysLeft: 10), days: 3, bytes: 5 * Traffic::GIGABYTE);
        self::assertSame('2026-10-03 12:00:00', $terms->expiry->deadline()?->format('Y-m-d H:i:s'));
        self::assertSame(33, $terms->durationDays);
        self::assertSame(40 * Traffic::GIGABYTE, $terms->totalBytes, 'counted from what it used, past its 30');

        $waiting = ServiceTerms::grant(self::row(usedGb: 0, daysLeft: null), days: 3, bytes: 0);
        self::assertSame(33 * 86400, $waiting->expiry->pendingSeconds(), 'a term still waiting grows');
        self::assertSame(30 * Traffic::GIGABYTE, $waiting->totalBytes);

        $endless = self::row(usedGb: 1, daysLeft: null, limitGb: 0);
        $endless->forceFill(['duration_days' => 0]);
        $nothing = ServiceTerms::grant($endless, days: 3, bytes: 5 * Traffic::GIGABYTE);
        self::assertSame([0, 0, null, null], [$nothing->durationDays, $nothing->totalBytes, $nothing->expiry->deadline(), $nothing->expiry->pendingSeconds()], 'no term takes no days, unlimited no traffic');

        $queued = self::row(usedGb: 20, daysLeft: 35, limitGb: 60);
        $queued->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);
        $moved = ServiceTerms::grant($queued, days: 2, bytes: 10 * Traffic::GIGABYTE);
        self::assertSame(['2026-09-27 12:00:00', 40 * Traffic::GIGABYTE], [$moved->periodEndsAt?->format('Y-m-d H:i:s'), $moved->nextPeriodBytes], 'the paid period gets the days, the traffic outlives it');
    }

    public function testTheRenewedPeriodKeepsAtMostItsTrafficCountedAfresh(): void
    {
        $service = self::row(usedGb: 24, daysLeft: 30, limitGb: 60);
        $service->forceFill(['next_period_bytes' => 30 * Traffic::GIGABYTE]);
        $terms = ServiceTerms::nextPeriod($service);
        self::assertNotNull($terms);
        self::assertSame([30 * Traffic::GIGABYTE, true], [$terms->totalBytes, $terms->resetCounters]);

        $dipped = self::row(usedGb: 35, daysLeft: 30, limitGb: 60);
        $dipped->forceFill(['next_period_bytes' => 30 * Traffic::GIGABYTE]);
        self::assertSame(25 * Traffic::GIGABYTE, ServiceTerms::nextPeriod($dipped)?->totalBytes, 'less when the customer already dipped into it');

        $usedUp = self::row(usedGb: 60, daysLeft: 30, limitGb: 60);
        $usedUp->forceFill(['next_period_bytes' => 30 * Traffic::GIGABYTE]);
        self::assertNull(ServiceTerms::nextPeriod($usedUp), 'nothing to cap');

        $off = self::row(usedGb: 24, daysLeft: 30, limitGb: 60, status: SubscriptionStatus::Disabled);
        $off->forceFill(['next_period_bytes' => 30 * Traffic::GIGABYTE]);
        self::assertNull(ServiceTerms::nextPeriod($off));
    }

    /** A 30-day service with `$limitGb` (0 = unlimited) and `$usedGb` used, `$daysLeft` to go — null: the clock never started. */
    private static function row(int $usedGb, ?int $daysLeft, int $limitGb = 30, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return (new Subscription())->forceFill([
            'status' => $status,
            'traffic_limit_bytes' => $limitGb * Traffic::GIGABYTE,
            'download_bytes' => $usedGb * Traffic::GIGABYTE,
            'upload_bytes' => 0,
            'duration_days' => 30,
            'ip_limit' => 1,
            'expires_at' => $daysLeft !== null ? now()->addDays($daysLeft) : null,
            'starts_at' => $daysLeft !== null ? now()->addDays($daysLeft - 30) : null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private static function plan(array $overrides = []): Plan
    {
        return (new Plan())->forceFill($overrides + ['duration_days' => 30, 'traffic_gb' => 30, 'ip_limit' => 2]);
    }
}
