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
 * Every rule of what a renewal, a grant, extra traffic or time and a renewed period give a service, and what a refund
 * takes back of them, worked out from the row alone — the numbers ProvisioningService then sends to the panel.
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
        self::assertSame(60 * 24, $terms->durationHours);
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
        self::assertSame(30 * 24, $terms->durationHours);
        self::assertSame(30 * Traffic::GIGABYTE, $terms->totalBytes, 'what was left went with the period');
        self::assertTrue($terms->resetCounters);
        self::assertNull($terms->periodEndsAt);

        self::assertSame(40 * Traffic::GIGABYTE, ServiceTerms::renewal($ended(), self::plan(), carryTraffic: true)->totalBytes, 'carried: the 10 left on top');

        $preview = $terms->preview($ended());
        self::assertSame([null, null, 30 * 24], [$preview->starts_at, $preview->expires_at, $preview->duration_hours], 'shown before it is paid: no start until its next connection, as the panel will say');
        $running = self::row(usedGb: 20, daysLeft: 5);
        self::assertEquals($running->starts_at, ServiceTerms::renewal($running, self::plan(), carryTraffic: false)->preview($running)->starts_at, 'a running one keeps its start');
    }

    public function testAPlanWithoutATermOrWithoutAQuotaPassesThatOn(): void
    {
        $never = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['duration_hours' => 0]), carryTraffic: false);
        self::assertSame([null, null, 0], [$never->expiry->deadline(), $never->expiry->pendingSeconds(), $never->durationHours]);

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
        self::assertSame(33 * 24, $terms->durationHours);
        self::assertSame(40 * Traffic::GIGABYTE, $terms->totalBytes, 'counted from what it used, past its 30');

        $waiting = ServiceTerms::grant(self::row(usedGb: 0, daysLeft: null), days: 3, bytes: 0);
        self::assertSame(33 * 86400, $waiting->expiry->pendingSeconds(), 'a term still waiting grows');
        self::assertSame(30 * Traffic::GIGABYTE, $waiting->totalBytes);

        $endless = self::row(usedGb: 1, daysLeft: null, limitGb: 0);
        $endless->forceFill(['duration_hours' => 0]);
        $nothing = ServiceTerms::grant($endless, days: 3, bytes: 5 * Traffic::GIGABYTE);
        self::assertSame([0, 0, null, null], [$nothing->durationHours, $nothing->totalBytes, $nothing->expiry->deadline(), $nothing->expiry->pendingSeconds()], 'no term takes no days, unlimited no traffic');

        $queued = self::row(usedGb: 20, daysLeft: 35, limitGb: 60);
        $queued->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);
        $moved = ServiceTerms::grant($queued, days: 2, bytes: 10 * Traffic::GIGABYTE);
        self::assertSame(['2026-09-27 12:00:00', 40 * Traffic::GIGABYTE], [$moved->periodEndsAt?->format('Y-m-d H:i:s'), $moved->nextPeriodBytes], 'the paid period gets the days, the traffic outlives it');
    }

    public function testAnExtraIsWhatAGrantGivesButDaysForAServiceWhoseTimeRanOutStartItsTermAgain(): void
    {
        $traffic = ServiceTerms::extra(self::row(usedGb: 30, daysLeft: 10, status: SubscriptionStatus::Expired), days: 0, bytes: 10 * Traffic::GIGABYTE);
        self::assertSame(40 * Traffic::GIGABYTE, $traffic->totalBytes, 'a service whose traffic ran out has 10 to go again');
        self::assertSame(['2026-09-30 12:00:00', 30 * 24], [$traffic->expiry->deadline()?->format('Y-m-d H:i:s'), $traffic->durationHours], 'its term as it was');
        self::assertFalse($traffic->resetCounters);

        $running = ServiceTerms::extra(self::row(usedGb: 5, daysLeft: 10), days: 7, bytes: 0);
        self::assertSame(['2026-10-07 12:00:00', 37 * 24, 30 * Traffic::GIGABYTE], [$running->expiry->deadline()?->format('Y-m-d H:i:s'), $running->durationHours, $running->totalBytes], 'onto its deadline, its quota as it was');
        $waiting = ServiceTerms::extra(self::row(usedGb: 0, daysLeft: null), days: 7, bytes: 0);
        self::assertSame(37 * 86400, $waiting->expiry->pendingSeconds(), 'a term still waiting for the first connection grows');

        $ended = self::row(usedGb: 12, daysLeft: -3, status: SubscriptionStatus::Expired);
        $ended->forceFill(['period_ends_at' => now()->subDays(3), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);
        $restarted = ServiceTerms::extra($ended, days: 7, bytes: 0);
        self::assertSame([null, 7 * 86400, 7 * 24], [$restarted->expiry->deadline(), $restarted->expiry->pendingSeconds(), $restarted->durationHours], 'seven days from its next connection: added to a deadline gone by, they would leave it ended');
        self::assertSame([30 * Traffic::GIGABYTE, false], [$restarted->totalBytes, $restarted->resetCounters], 'its traffic as it was: 18 GB still to use');
        self::assertSame([null, null], [$restarted->periodEndsAt, $restarted->nextPeriodBytes], 'a period queued behind the one that ended went with it');

        $preview = $restarted->preview($ended);
        self::assertSame([SubscriptionStatus::Active, null, null, 7 * 24], [$preview->status, $preview->starts_at, $preview->expires_at, $preview->duration_hours], 'shown before it is paid: running again, its clock waiting');
    }

    public function testAnHourlyPlanIsCountedByTheHour(): void
    {
        $plan = self::plan(['duration_hours' => 6]);

        $running = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), $plan, carryTraffic: false);
        self::assertSame(['2026-09-25 18:00:00', 720 + 6], [$running->expiry->deadline()?->format('Y-m-d H:i:s'), $running->durationHours], 'six hours onto its deadline');
        self::assertSame((720 + 6) * 3600, ServiceTerms::renewal(self::row(usedGb: 0, daysLeft: null), $plan, carryTraffic: false)->expiry->pendingSeconds(), 'a term still waiting grows by six hours');
        $ended = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired), $plan, carryTraffic: false);
        self::assertSame([null, 6 * 3600, 6], [$ended->expiry->deadline(), $ended->expiry->pendingSeconds(), $ended->durationHours], 'six hours from its next connection');

        $short = self::row(usedGb: 1, daysLeft: null)->forceFill(['duration_hours' => 6, 'expires_at' => now()->addHours(2), 'starts_at' => now()->subHours(4)]);
        $granted = ServiceTerms::grant($short, days: 3, bytes: 0);
        self::assertSame(['2026-09-23 14:00:00', 6 + 72], [$granted->expiry->deadline()?->format('Y-m-d H:i:s'), $granted->durationHours], 'a grant gives an hourly service its days in hours');

        self::assertEquals(now()->subHours(4), ServiceTerms::startedAt($short->forceFill(['starts_at' => null]), now()->addHours(2), now()), 'its start counted back from its deadline by its hours');
    }

    public function testATermInWholeDaysIsRoundedUpAndOnlyNoneIsZero(): void
    {
        self::assertSame([0, 1, 1, 2, 30], [ServiceTerms::daysOf(0), ServiceTerms::daysOf(6), ServiceTerms::daysOf(24), ServiceTerms::daysOf(25), ServiceTerms::daysOf(720)], 'a six-hour term is never read as one that never ends');
        self::assertSame([6 * 3600, null], [ServiceTerms::termOf(6)->pendingSeconds(), ServiceTerms::termOf(0)->pendingSeconds()]);
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

    public function testTheTermsDecideTheRowsTermAndQueuedPeriodThemselves(): void
    {
        $renewal = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(), carryTraffic: false);
        self::assertSame([
            'duration_hours' => 60 * 24,
            'period_ends_at' => '2026-09-25 12:00:00',
            'next_period_bytes' => 30 * Traffic::GIGABYTE,
        ], array_replace($renewal->columns(), ['period_ends_at' => $renewal->periodEndsAt?->format('Y-m-d H:i:s')]));
        self::assertSame(2, $renewal->ipLimit, "a renewal takes its plan's IP limit");

        $grant = ServiceTerms::grant(self::row(usedGb: 20, daysLeft: 5), 3, 0);
        self::assertSame(['duration_hours' => 720 + 3 * 24, 'period_ends_at' => null, 'next_period_bytes' => null], $grant->columns());
        self::assertSame(1, $grant->ipLimit, "a grant keeps the service's own IP limit");
    }

    public function testATakeBackUndoesWhatARenewalGave(): void
    {
        // Renewed on the 30-day, 30 GB plan: 35 days and 60 GB, 20 used.
        $renewed = self::row(usedGb: 20, daysLeft: 35, limitGb: 60)->forceFill(['duration_hours' => 1440]);

        $terms = ServiceTerms::takeBack($renewed, 720, 30 * Traffic::GIGABYTE, renewal: true);

        self::assertSame('2026-09-25 12:00:00', $terms->expiry->deadline()?->format('Y-m-d H:i:s'), 'back to its old deadline');
        self::assertSame([720, 30 * Traffic::GIGABYTE], [$terms->durationHours, $terms->totalBytes]);
        self::assertFalse($terms->resetCounters);
    }

    public function testATakeBackLeavesTheServiceAsEachRenewalOrExtraFoundIt(): void
    {
        $running = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 5);
        $waiting = static fn(): Subscription => self::row(usedGb: 0, daysLeft: null);
        $renewal = static fn(Plan $plan, bool $carry): \Closure => static fn(Subscription $row): ServiceTerms => ServiceTerms::renewal($row, $plan, $carry);
        $extra = static fn(int $days, int $gb): \Closure => static fn(Subscription $row): ServiceTerms => ServiceTerms::extra($row, $days, $gb * Traffic::GIGABYTE);
        $cases = [
            'a running service renewed, traffic carried' => [$running, $renewal(self::plan(), true), 720, 30, true],
            'a running service renewed, its period queued' => [$running, $renewal(self::plan(), false), 720, 30, true],
            'a term not started, renewed' => [$waiting, $renewal(self::plan(), false), 720, 30, true],
            'an hourly renewal' => [$running, $renewal(self::plan(['duration_hours' => 6, 'traffic_gb' => 1]), true), 6, 1, true],
            'extra days on a running service' => [$running, $extra(7, 0), 7 * 24, 0, false],
            'extra days on a term not started' => [$waiting, $extra(7, 0), 7 * 24, 0, false],
            'extra traffic' => [$running, $extra(0, 10), 0, 10, false],
        ];

        foreach ($cases as $case => [$row, $give, $hours, $gb, $isRenewal]) {
            $given = $give($row())->preview($row());
            $back = ServiceTerms::takeBack($given, $hours, $gb * Traffic::GIGABYTE, $isRenewal);

            $was = $row();
            self::assertSame(
                [$was->expires_at?->format('Y-m-d H:i:s'), ServiceTerms::expiryOf($was)->pendingSeconds(), 720, 30 * Traffic::GIGABYTE, null, null],
                [$back->expiry->deadline()?->format('Y-m-d H:i:s'), $back->expiry->pendingSeconds(), $back->durationHours, $back->totalBytes, $back->periodEndsAt, $back->nextPeriodBytes],
                $case,
            );
        }
    }

    public function testATakeBackEndsAServiceThatHadEndedBeforeIt(): void
    {
        $ended = self::row(usedGb: 30, daysLeft: -1, status: SubscriptionStatus::Expired);

        $renewed = ServiceTerms::takeBack(ServiceTerms::renewal($ended, self::plan(), carryTraffic: false)->preview($ended), 720, 30 * Traffic::GIGABYTE, renewal: true);
        self::assertSame(['2026-09-20 12:00:00', 1], [$renewed->expiry->deadline()?->format('Y-m-d H:i:s'), $renewed->totalBytes], 'its new term and fresh count gone: it ends now');

        $days = ServiceTerms::takeBack(ServiceTerms::extra($ended, 7, 0)->preview($ended), 7 * 24, 0, renewal: false);
        self::assertSame('2026-09-20 12:00:00', $days->expiry->deadline()?->format('Y-m-d H:i:s'), 'the days that restarted it gone: it ends now');
    }

    public function testATakeBackPastWhatIsLeftEndsTheServiceNow(): void
    {
        $terms = ServiceTerms::takeBack(self::row(usedGb: 50, daysLeft: 3, limitGb: 60), 30 * 24, 30 * Traffic::GIGABYTE, renewal: true);

        self::assertSame('2026-09-20 12:00:00', $terms->expiry->deadline()?->format('Y-m-d H:i:s'), 'its deadline moved before now: it ends now');
        self::assertSame(30 * Traffic::GIGABYTE, $terms->totalBytes, 'used 50 of what is now 30: it ends by traffic too');

        $waiting = ServiceTerms::takeBack(self::row(usedGb: 0, daysLeft: null), 30 * 24, 0, renewal: true);
        self::assertSame('2026-09-20 12:00:00', $waiting->expiry->deadline()?->format('Y-m-d H:i:s'), 'a term never started with nothing left of it ends now');

        $shorter = ServiceTerms::takeBack(self::row(usedGb: 0, daysLeft: null)->forceFill(['duration_hours' => 1440]), 30 * 24, 0, renewal: true);
        self::assertSame(30 * 86400, $shorter->expiry->pendingSeconds(), 'what is left of it still waits for the first connection');

        $all = ServiceTerms::takeBack(self::row(usedGb: 0, daysLeft: 3), 0, 30 * Traffic::GIGABYTE, renewal: false);
        self::assertSame(1, $all->totalBytes, 'never 0, which would be unlimited');
    }

    public function testATakeBackLeavesWhatCannotBeCounted(): void
    {
        $unlimited = ServiceTerms::takeBack(self::row(usedGb: 5, daysLeft: 10, limitGb: 0), 0, 30 * Traffic::GIGABYTE, renewal: true);
        self::assertSame(0, $unlimited->totalBytes, 'still unlimited');

        $endless = ServiceTerms::takeBack(self::row(usedGb: 5, daysLeft: null)->forceFill(['duration_hours' => 0]), 30 * 24, 0, renewal: true);
        self::assertNull($endless->expiry->deadline());
        self::assertNull($endless->expiry->pendingSeconds(), 'still never ends');
    }

    public function testATakeBackShrinksAQueuedPeriod(): void
    {
        $queued = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 35, limitGb: 60)->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);

        $renewal = ServiceTerms::takeBack($queued(), 720, 30 * Traffic::GIGABYTE, renewal: true);
        self::assertSame([null, null], [$renewal->periodEndsAt, $renewal->nextPeriodBytes], 'the renewal taken back: nothing queued');

        $extra = ServiceTerms::takeBack($queued(), 2 * 24, 10 * Traffic::GIGABYTE, renewal: false);
        self::assertSame(['2026-09-23 12:00:00', 20 * Traffic::GIGABYTE], [$extra->periodEndsAt?->format('Y-m-d H:i:s'), $extra->nextPeriodBytes], 'an extra\'s days move it back, its traffic comes off');
    }

    /** A 30-day service with `$limitGb` (0 = unlimited) and `$usedGb` used, `$daysLeft` to go — null: the clock never started. */
    private static function row(int $usedGb, ?int $daysLeft, int $limitGb = 30, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        return (new Subscription())->forceFill([
            'status' => $status,
            'traffic_limit_bytes' => $limitGb * Traffic::GIGABYTE,
            'download_bytes' => $usedGb * Traffic::GIGABYTE,
            'upload_bytes' => 0,
            'duration_hours' => 720,
            'ip_limit' => 1,
            'expires_at' => $daysLeft !== null ? now()->addDays($daysLeft) : null,
            'starts_at' => $daysLeft !== null ? now()->addDays($daysLeft - 30) : null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private static function plan(array $overrides = []): Plan
    {
        return (new Plan())->forceFill($overrides + ['duration_hours' => 720, 'traffic_gb' => 30, 'ip_limit' => 2]);
    }
}
