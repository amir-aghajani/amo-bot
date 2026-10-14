<?php

declare(strict_types=1);

namespace Tests\Unit\Subscriptions;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Every rule of what a renewal, a grant, extra traffic, time or devices and a renewed period give a service, and what
 * a refund takes back of them, worked out from the row alone — the numbers ProvisioningService then sends to the panel.
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
        $terms = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan()->package(), carryTraffic: false);

        self::assertSame('2026-10-25 12:00:00', $terms->expiry->deadline()?->format('Y-m-d H:i:s'), 'the days left carry');
        self::assertSame(60 * 24, $terms->durationHours);
        self::assertSame(60 * Traffic::GIGABYTE, $terms->totalBytes, 'nothing taken away now');
        self::assertFalse($terms->resetCounters);
        self::assertSame(['2026-09-25 12:00:00', 30 * Traffic::GIGABYTE], [$terms->periodEndsAt?->format('Y-m-d H:i:s'), $terms->nextPeriodBytes], 'not carried: what this period leaves goes when it ends');

        $carried = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan()->package(), carryTraffic: true);
        self::assertSame(60 * Traffic::GIGABYTE, $carried->totalBytes);
        self::assertNull($carried->nextPeriodBytes, 'carried: nothing to take away later');
        self::assertSame('2026-09-25 12:00:00', $carried->periodEndsAt?->format('Y-m-d H:i:s'), "the plan's devices still wait for the period's end");

        $usedUp = ServiceTerms::renewal(self::row(usedGb: 30, daysLeft: 5), self::plan()->package(), carryTraffic: false);
        self::assertNull($usedUp->periodEndsAt, 'nothing left of this period to take away');
    }

    public function testATermNotStartedGrowsAndStillWaitsForTheFirstConnection(): void
    {
        $terms = ServiceTerms::renewal(self::row(usedGb: 0, daysLeft: null), self::plan()->package(), carryTraffic: false);

        self::assertNull($terms->expiry->deadline());
        self::assertSame(60 * 86400, $terms->expiry->pendingSeconds());
        self::assertSame(60 * Traffic::GIGABYTE, $terms->totalBytes);
        self::assertNull($terms->periodEndsAt);
    }

    public function testAnEndedServiceStartsAFreshCountAndANewTermAtItsNextConnection(): void
    {
        $ended = static fn(): Subscription => self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired);

        $terms = ServiceTerms::renewal($ended(), self::plan()->package(), carryTraffic: false);
        self::assertSame(30 * 86400, $terms->expiry->pendingSeconds());
        self::assertSame(30 * 24, $terms->durationHours);
        self::assertSame(30 * Traffic::GIGABYTE, $terms->totalBytes, 'what was left went with the period');
        self::assertTrue($terms->resetCounters);
        self::assertNull($terms->periodEndsAt);

        self::assertSame(40 * Traffic::GIGABYTE, ServiceTerms::renewal($ended(), self::plan()->package(), carryTraffic: true)->totalBytes, 'carried: the 10 left on top');

        $preview = $terms->preview($ended());
        self::assertSame([null, null, 30 * 24], [$preview->starts_at, $preview->expires_at, $preview->duration_hours], 'shown before it is paid: no start until its next connection, as the panel will say');
        $running = self::row(usedGb: 20, daysLeft: 5);
        self::assertEquals($running->starts_at, ServiceTerms::renewal($running, self::plan()->package(), carryTraffic: false)->preview($running)->starts_at, 'a running one keeps its start');
    }

    public function testAPlanWithoutATermOrWithoutAQuotaPassesThatOn(): void
    {
        $never = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['duration_hours' => 0])->package(), carryTraffic: false);
        self::assertSame([null, null, 0], [$never->expiry->deadline(), $never->expiry->pendingSeconds(), $never->durationHours]);

        $unlimited = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['traffic_gb' => 0])->package(), carryTraffic: false);
        self::assertSame([0, null], [$unlimited->totalBytes, $unlimited->nextPeriodBytes], 'unlimited, no traffic queued');

        $wasUnlimited = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5, limitGb: 0), self::plan()->package(), carryTraffic: false);
        self::assertSame([30 * Traffic::GIGABYTE, true], [$wasUnlimited->totalBytes, $wasUnlimited->resetCounters], 'a limited plan on an unlimited service: a fresh count');
    }

    public function testARenewalQueuedBehindAnotherAddsToIt(): void
    {
        $service = self::row(usedGb: 20, daysLeft: 35, limitGb: 60);
        $service->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);

        $terms = ServiceTerms::renewal($service, self::plan()->package(), carryTraffic: false);

        self::assertSame(90 * Traffic::GIGABYTE, $terms->totalBytes);
        self::assertSame(['2026-09-25 12:00:00', 60 * Traffic::GIGABYTE], [$terms->periodEndsAt?->format('Y-m-d H:i:s'), $terms->nextPeriodBytes], 'the first period still ends when it did');
    }

    public function testAResetOfTheTrafficKeepsTheDaysLeftAndCountsThePackageAfresh(): void
    {
        foreach ([false, true] as $carry) {
            $running = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan()->package(), $carry, RenewalMethod::ResetTraffic);
            self::assertSame(['2026-10-25 12:00:00', 60 * 24, null], [$running->expiry->deadline()?->format('Y-m-d H:i:s'), $running->durationHours, $running->startsAt], 'the days left carry, the clock runs on');
            self::assertSame([30 * Traffic::GIGABYTE, true, null], [$running->totalBytes, $running->resetCounters, $running->nextPeriodBytes], 'the 10 left are lost whatever the shop carries: no traffic queued');
            self::assertSame(['2026-09-25 12:00:00', 2], [$running->periodEndsAt?->format('Y-m-d H:i:s'), $running->nextPeriodIpLimit], "the plan's devices wait for the period in use to end");
        }

        $waiting = ServiceTerms::renewal(self::row(usedGb: 0, daysLeft: null), self::plan()->package(), false, RenewalMethod::ResetTraffic);
        self::assertSame([null, 60 * 86400, 30 * Traffic::GIGABYTE, true], [$waiting->expiry->deadline(), $waiting->expiry->pendingSeconds(), $waiting->totalBytes, $waiting->resetCounters], 'a term not started grows, still waiting for the first connection');

        $ended = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired), self::plan()->package(), true, RenewalMethod::ResetTraffic);
        self::assertSame([null, 30 * 86400, 30 * Traffic::GIGABYTE, true], [$ended->expiry->deadline(), $ended->expiry->pendingSeconds(), $ended->totalBytes, $ended->resetCounters], 'an ended one: a new term from its next connection, the package alone though traffic is carried');

        $unlimited = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['traffic_gb' => 0])->package(), false, RenewalMethod::ResetTraffic);
        self::assertSame([0, true], [$unlimited->totalBytes, $unlimited->resetCounters], 'an unlimited package: unlimited, counted afresh');
    }

    public function testAResetOfTheTimeStartsTheTermAgainAndCarriesTheTrafficLeft(): void
    {
        foreach ([false, true] as $carry) {
            $running = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan()->package(), $carry, RenewalMethod::ResetTime);
            self::assertSame(['2026-10-20 12:00:00', 30 * 24, '2026-09-20 12:00:00'], [$running->expiry->deadline()?->format('Y-m-d H:i:s'), $running->durationHours, $running->startsAt?->format('Y-m-d H:i:s')], 'thirty days from now: the five left are lost, the clock starts again now');
            self::assertSame([60 * Traffic::GIGABYTE, false, null, null], [$running->totalBytes, $running->resetCounters, $running->periodEndsAt, $running->nextPeriodBytes], 'the package on top of the quota, whatever the shop carries: the 10 left stay');
        }

        $renewed = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan()->package(), false, RenewalMethod::ResetTime);
        $preview = $renewed->preview(self::row(usedGb: 20, daysLeft: 5));
        self::assertSame(['2026-09-20 12:00:00', '2026-10-20 12:00:00', 30 * 24], [$preview->starts_at?->format('Y-m-d H:i:s'), $preview->expires_at?->format('Y-m-d H:i:s'), $preview->duration_hours], 'shown before it is paid: started now');
        self::assertSame('2026-09-20 12:00:00', $renewed->columns()['starts_at']?->format('Y-m-d H:i:s'), 'the row keeps the new start');

        $waiting = ServiceTerms::renewal(self::row(usedGb: 0, daysLeft: null), self::plan()->package(), false, RenewalMethod::ResetTime);
        self::assertSame([null, 30 * 86400, null, 60 * Traffic::GIGABYTE], [$waiting->expiry->deadline(), $waiting->expiry->pendingSeconds(), $waiting->startsAt, $waiting->totalBytes], 'a term not started is the package\'s, still from the first connection');
        self::assertArrayNotHasKey('starts_at', $waiting->columns(), 'no start to keep yet');

        $ended = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired), self::plan()->package(), false, RenewalMethod::ResetTime);
        self::assertSame([null, 30 * 86400, 40 * Traffic::GIGABYTE, true], [$ended->expiry->deadline(), $ended->expiry->pendingSeconds(), $ended->totalBytes, $ended->resetCounters], 'an ended one: a new term from its next connection, the 10 left carried though the shop carries none');

        $wasUnlimited = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5, limitGb: 0), self::plan()->package(), false, RenewalMethod::ResetTime);
        self::assertSame([30 * Traffic::GIGABYTE, true], [$wasUnlimited->totalBytes, $wasUnlimited->resetCounters], 'an unlimited service on a limited package: a fresh count');
        $never = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan(['duration_hours' => 0])->package(), false, RenewalMethod::ResetTime);
        self::assertSame([null, null, 0, null], [$never->expiry->deadline(), $never->expiry->pendingSeconds(), $never->durationHours, $never->startsAt], 'a package without a term: it never ends');
    }

    public function testOnlyAnExtensionQueuesBehindThePeriodInUse(): void
    {
        $queued = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 35, limitGb: 60)->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE]);

        $traffic = ServiceTerms::renewal($queued(), self::plan()->package(), false, RenewalMethod::ResetTraffic);
        self::assertSame([30 * Traffic::GIGABYTE, true, null], [$traffic->totalBytes, $traffic->resetCounters, $traffic->nextPeriodBytes], 'counted afresh: the traffic queued goes with the rest');
        self::assertSame('2026-09-25 12:00:00', $traffic->periodEndsAt?->format('Y-m-d H:i:s'), 'the period in use still ends when it did, for its devices');
        self::assertSame(['2026-11-24 12:00:00', 60 * 24], [$traffic->expiry->deadline()?->format('Y-m-d H:i:s'), $traffic->durationHours], 'its days on top of the deadline');

        $time = ServiceTerms::renewal($queued(), self::plan()->package(), false, RenewalMethod::ResetTime);
        self::assertSame([90 * Traffic::GIGABYTE, false, null, null], [$time->totalBytes, $time->resetCounters, $time->periodEndsAt, $time->nextPeriodBytes], 'everything left carried: nothing to take away later');
        self::assertSame('2026-10-20 12:00:00', $time->expiry->deadline()?->format('Y-m-d H:i:s'), 'thirty days from now');

        $extend = ServiceTerms::renewal($queued(), self::plan()->package(), false, RenewalMethod::Extend);
        self::assertSame(['2026-09-25 12:00:00', 60 * Traffic::GIGABYTE], [$extend->periodEndsAt?->format('Y-m-d H:i:s'), $extend->nextPeriodBytes], 'an extension adds to the queued period');
    }

    public function testARunningServiceKeepsTheDevicesOfThePeriodInUseUntilItEndsThenThePackages(): void
    {
        // Five devices: the plan's one and four bought for the period in use.
        $five = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 5)->forceFill(['ip_limit' => 5]);
        $devices = static fn(ServiceTerms $terms): array => [$terms->ipLimit, $terms->nextPeriodIpLimit, $terms->periodEndsAt?->format('Y-m-d H:i:s'), $terms->limitResetAt?->format('Y-m-d H:i:s')];

        foreach ([RenewalMethod::Extend, RenewalMethod::ResetTraffic] as $method) {
            foreach ([true, false] as $carry) {
                $terms = ServiceTerms::renewal($five(), self::plan()->package(), $carry, $method);
                self::assertSame([5, 2, '2026-09-25 12:00:00', null], $devices($terms), "{$method->value}: the five last until the period in use ends, then the plan's two");
            }
            self::assertSame([5, 0], [ServiceTerms::renewal($five(), self::plan(['ip_limit' => 0])->package(), false, $method)->ipLimit, ServiceTerms::renewal($five(), self::plan(['ip_limit' => 0])->package(), false, $method)->nextPeriodIpLimit], "{$method->value}: an unlimited plan's, from then too");
            self::assertSame([0, 2], [ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5)->forceFill(['ip_limit' => 0]), self::plan()->package(), false, $method)->ipLimit, ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5)->forceFill(['ip_limit' => 0]), self::plan()->package(), false, $method)->nextPeriodIpLimit], "{$method->value}: an unlimited service is unlimited no more once the plan's period begins");
            self::assertSame([5, 5], [ServiceTerms::renewal($five(), self::plan(['ip_limit' => 5])->package(), false, $method)->ipLimit, ServiceTerms::renewal($five(), self::plan(['ip_limit' => 5])->package(), false, $method)->nextPeriodIpLimit], "{$method->value}: queued the same, so the period's end is known");
        }

        self::assertSame([2, null, null, '2026-09-20 12:00:00'], $devices(ServiceTerms::renewal($five(), self::plan()->package(), false, RenewalMethod::ResetTime)), "reset_time: its term starts over now, with the plan's two");

        $queued = $five()->forceFill(['expires_at' => now()->addDays(35), 'traffic_limit_bytes' => 60 * Traffic::GIGABYTE, 'period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE, 'next_period_ip_limit' => 2]);
        self::assertSame([5, 3, '2026-09-25 12:00:00', null], $devices(ServiceTerms::renewal($queued, self::plan(['ip_limit' => 3])->package(), false)), 'a renewal queued behind a pending one: its own plan\'s three from the same end');

        // Queued for the devices alone (traffic carried), then renewed without carrying: the traffic is queued at the
        // deadline as it always was, and the devices with it.
        $devicesOnly = $five()->forceFill(['expires_at' => now()->addDays(35), 'period_ends_at' => now()->addDays(5), 'next_period_ip_limit' => 2]);
        $later = ServiceTerms::renewal($devicesOnly, self::plan()->package(), false);
        self::assertSame(['2026-10-25 12:00:00', 30 * Traffic::GIGABYTE, 2], [$later->periodEndsAt?->format('Y-m-d H:i:s'), $later->nextPeriodBytes, $later->nextPeriodIpLimit]);
    }

    public function testAServiceWhosePeriodIsOverTakesThePackagesDevicesAtOnce(): void
    {
        $five = static fn(Subscription $row): Subscription => $row->forceFill(['ip_limit' => 5]);
        $services = [
            'ended by time' => static fn(): Subscription => $five(self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired)),
            'ended by traffic' => static fn(): Subscription => $five(self::row(usedGb: 30, daysLeft: 5, status: SubscriptionStatus::Expired)),
            'never ending' => static fn(): Subscription => $five(self::row(usedGb: 1, daysLeft: null))->forceFill(['duration_hours' => 0]),
        ];

        foreach (RenewalMethod::cases() as $method) {
            foreach ($services as $case => $service) {
                $terms = ServiceTerms::renewal($service(), self::plan()->package(), false, $method);
                self::assertSame([2, null, '2026-09-20 12:00:00'], [$terms->ipLimit, $terms->nextPeriodIpLimit, $terms->limitResetAt?->format('Y-m-d H:i:s')], "{$method->value}, {$case}: the plan's two, the devices bought gone with the period");
                self::assertSame(0, ServiceTerms::renewal($service(), self::plan(['ip_limit' => 0])->package(), false, $method)->ipLimit, "{$method->value}, {$case}: an unlimited plan's");
                self::assertSame(2, ServiceTerms::renewal($service()->forceFill(['ip_limit' => 0]), self::plan()->package(), false, $method)->ipLimit, "{$method->value}, {$case}: an unlimited service gets the plan's limit");
            }
        }
    }

    public function testAServiceWaitingForItsFirstConnectionKeepsTheDevicesBoughtOnTopOfThePackages(): void
    {
        // Four devices: the plan's one and three bought for its term, none of which has run.
        $waiting = static fn(): Subscription => self::row(usedGb: 0, daysLeft: null)->forceFill(['ip_limit' => 4]);

        foreach ([RenewalMethod::Extend, RenewalMethod::ResetTraffic] as $method) {
            $terms = ServiceTerms::renewal($waiting(), self::plan()->package(), false, $method, devicesBought: 3);
            self::assertSame([5, null, null, null], [$terms->ipLimit, $terms->nextPeriodIpLimit, $terms->periodEndsAt, $terms->limitResetAt], "{$method->value}: the plan's two at once and the three bought on top, for its whole term");
            self::assertSame(0, ServiceTerms::renewal($waiting(), self::plan(['ip_limit' => 0])->package(), false, $method, devicesBought: 3)->ipLimit, "{$method->value}: an unlimited plan's");
            self::assertSame(2, ServiceTerms::renewal($waiting()->forceFill(['ip_limit' => 0]), self::plan()->package(), false, $method)->ipLimit, "{$method->value}: an unlimited service gets the plan's limit");
        }

        $reset = ServiceTerms::renewal($waiting(), self::plan()->package(), false, RenewalMethod::ResetTime, devicesBought: 3);
        self::assertSame([2, '2026-09-20 12:00:00'], [$reset->ipLimit, $reset->limitResetAt?->format('Y-m-d H:i:s')], 'reset_time replaces the term they were bought for: the plan\'s two');
    }

    public function testARenewalKeepsOnItsOrderWhatItAddsBeyondWhatTheServiceHad(): void
    {
        $given = static fn(Subscription $row, RenewalMethod $method, ?Plan $plan = null): array => array_slice(ServiceTerms::renewal($row, ($plan ?? self::plan())->package(), false, $method)->given($row), 0, 2);
        $running = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 5);
        $waiting = static fn(): Subscription => self::row(usedGb: 0, daysLeft: null);
        $ended = static fn(): Subscription => self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired);
        $package = ['given_hours' => 720, 'given_bytes' => 30 * Traffic::GIGABYTE];

        foreach ([$running, $waiting, $ended] as $row) {
            self::assertSame($package, $given($row(), RenewalMethod::Extend), 'an extension adds the package');
        }

        self::assertSame(['given_hours' => 600, 'given_bytes' => 30 * Traffic::GIGABYTE], $given($running(), RenewalMethod::ResetTime), 'a reset of the time: 30 days less the 5 it replaced');
        self::assertSame(599, $given($running()->forceFill(['expires_at' => now()->addDays(5)->addMinutes(30)]), RenewalMethod::ResetTime)['given_hours'], 'a part of an hour left counts as one');
        self::assertSame(6 - 120, $given($running(), RenewalMethod::ResetTime, self::plan(['duration_hours' => 6]))['given_hours'], 'six hours in place of five days: less than nothing, for its refund to give back');
        self::assertSame(0, $given($waiting(), RenewalMethod::ResetTime)['given_hours'], 'the 30 days still waiting replaced by 30');
        self::assertSame(168 - 720, $given($waiting(), RenewalMethod::ResetTime, self::plan(['duration_hours' => 168]))['given_hours'], 'the 30 days still waiting replaced by seven');
        self::assertSame($package, $given($ended(), RenewalMethod::ResetTime), 'an ended one: a new term');

        self::assertSame(['given_hours' => 720, 'given_bytes' => 20 * Traffic::GIGABYTE], $given($running(), RenewalMethod::ResetTraffic), 'a reset of the traffic: 30 GB less the 10 it replaced');
        self::assertSame(-30 * Traffic::GIGABYTE, $given(self::row(usedGb: 10, daysLeft: 5, limitGb: 50), RenewalMethod::ResetTraffic, self::plan(['traffic_gb' => 10]))['given_bytes'], '10 GB in place of the 40 left: 30 less than nothing');
        self::assertSame(0, $given($waiting(), RenewalMethod::ResetTraffic)['given_bytes'], 'the 30 GB unused replaced by 30');
        self::assertSame(30 * Traffic::GIGABYTE, $given(self::row(usedGb: 30, daysLeft: 5), RenewalMethod::ResetTraffic)['given_bytes'], 'nothing was left');
        self::assertSame($package, $given($ended(), RenewalMethod::ResetTraffic), 'an ended one: a fresh count');
        self::assertSame(30 * Traffic::GIGABYTE, $given(self::row(usedGb: 20, daysLeft: 5, limitGb: 0), RenewalMethod::ResetTraffic)['given_bytes'], 'an unlimited service: the package');
    }

    public function testATakeBackOfEachMethodLeavesTheServiceAsTheRenewalFoundIt(): void
    {
        $running = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 5)->forceFill(['ip_limit' => 3]);
        $waiting = static fn(): Subscription => self::row(usedGb: 4, daysLeft: null)->forceFill(['ip_limit' => 3]);

        foreach (RenewalMethod::cases() as $method) {
            foreach (['running' => $running, 'waiting' => $waiting] as $state => $row) {
                $terms = ServiceTerms::renewal($row(), self::plan()->package(), false, $method);
                $given = $terms->given($row());
                $back = ServiceTerms::takeBack($terms->preview($row()), $given['given_hours'], $given['given_bytes'], renewal: true)
                    ->limitPutBack($given['given_ip_limit'], $given['ip_limit_was'], $given['ip_limit_queued']);
                $renewed = $terms->preview($row());
                $was = $row();

                self::assertSame(
                    [$was->expires_at?->format('Y-m-d H:i:s'), ServiceTerms::expiryOf($was)->pendingSeconds(), $was->remainingBytes(), 3, null, null],
                    [$back->expiry->deadline()?->format('Y-m-d H:i:s'), $back->expiry->pendingSeconds(), $back->totalBytes - $renewed->usedBytes(), $back->ipLimit, $back->nextPeriodIpLimit, $back->periodEndsAt],
                    "{$method->value}, {$state}: its end, the traffic left and its devices back as they were",
                );
            }

            $ended = self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired);
            $terms = ServiceTerms::renewal($ended, self::plan()->package(), false, $method);
            $given = $terms->given($ended);
            $back = ServiceTerms::takeBack($terms->preview($ended), $given['given_hours'], $given['given_bytes'], renewal: true);
            self::assertSame('2026-09-20 12:00:00', $back->expiry->deadline()?->format('Y-m-d H:i:s'), "{$method->value}, ended: ends now");
        }
    }

    public function testATakeBackOfAResetThatReplacedMoreThanItGaveGivesThatBack(): void
    {
        // 25 days and 40 GB left (10 of 50 used), or 25 days still waiting; renewed on a seven-day, 10 GB plan.
        $short = self::plan(['duration_hours' => 168, 'traffic_gb' => 10]);
        $rows = [
            'running' => static fn(): Subscription => self::row(usedGb: 10, daysLeft: 25, limitGb: 50)->forceFill(['ip_limit' => 3]),
            'waiting' => static fn(): Subscription => self::row(usedGb: 0, daysLeft: null, limitGb: 50)->forceFill(['duration_hours' => 600, 'ip_limit' => 3]),
        ];

        foreach ([RenewalMethod::ResetTime, RenewalMethod::ResetTraffic] as $method) {
            foreach ($rows as $state => $row) {
                $terms = ServiceTerms::renewal($row(), $short->package(), false, $method);
                $given = $terms->given($row());
                $renewed = $terms->preview($row());
                $back = ServiceTerms::takeBack($renewed, $given['given_hours'], $given['given_bytes'], renewal: true)
                    ->limitPutBack($given['given_ip_limit'], $given['ip_limit_was'], $given['ip_limit_queued']);
                $was = $row();

                self::assertSame(
                    [$was->expires_at?->format('Y-m-d H:i:s'), ServiceTerms::expiryOf($was)->pendingSeconds(), $was->remainingBytes(), 3],
                    [$back->expiry->deadline()?->format('Y-m-d H:i:s'), $back->expiry->pendingSeconds(), $back->totalBytes - $renewed->usedBytes(), $back->ipLimit],
                    "{$method->value}, {$state}: the days and the traffic it threw away given back",
                );
            }
        }

        $queued = self::row(usedGb: 10, daysLeft: 25, limitGb: 50)->forceFill(['period_ends_at' => now()->addDays(5), 'next_period_bytes' => 10 * Traffic::GIGABYTE]);
        self::assertSame([60 * Traffic::GIGABYTE, 10 * Traffic::GIGABYTE], [ServiceTerms::takeBack($queued, 0, -10 * Traffic::GIGABYTE, renewal: true)->totalBytes, ServiceTerms::takeBack($queued, 0, -10 * Traffic::GIGABYTE, renewal: true)->nextPeriodBytes], 'traffic given back is the period in use\'s: the queued cap stays');
    }

    public function testARenewalOntoAPackageWithoutATermOrAQuotaKeepsWhatItReplaced(): void
    {
        $endless = self::plan(['duration_hours' => 0, 'traffic_gb' => 0]);
        $keptOf = static function (Subscription $row) use ($endless): array {
            $given = ServiceTerms::renewal($row, $endless->package(), false)->given($row);

            return [$given['term_was'], $given['expires_was']?->format('Y-m-d H:i:s'), $given['quota_was'], $given['given_hours'], $given['given_bytes'], $given['reset_term'], $given['reset_traffic']];
        };

        self::assertSame([720, '2026-09-25 12:00:00', 10 * Traffic::GIGABYTE, 0, 0, true, true], $keptOf(self::row(usedGb: 20, daysLeft: 5)), 'its term, its deadline and the 10 GB left: started afresh as none, back whole when refunded');
        self::assertSame([720, null, 30 * Traffic::GIGABYTE, 0, 0, true, true], $keptOf(self::row(usedGb: 0, daysLeft: null)), 'a term still waiting: no deadline');
        self::assertSame([null, null, null, 0, 0, false, false], $keptOf(self::row(usedGb: 5, daysLeft: null, limitGb: 0)->forceFill(['duration_hours' => 0])), 'one that never ended and was unlimited: nothing replaced');
        self::assertSame([720, null, false], array_values(array_intersect_key($keptOf(self::row(usedGb: 20, daysLeft: 5, limitGb: 0)), [0 => 0, 2 => 0, 6 => 0])), 'unlimited before: only its term to put back');
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
        self::assertSame([true, 7 * 24], [$restarted->restartsTerm, $restarted->givenHours], 'a new term, kept on the order as a renewal\'s restart is');
        self::assertFalse($running->restartsTerm, 'days onto a running term start nothing afresh');
        self::assertSame([30 * Traffic::GIGABYTE, false], [$restarted->totalBytes, $restarted->resetCounters], 'its traffic as it was: 18 GB still to use');
        self::assertSame([null, null], [$restarted->periodEndsAt, $restarted->nextPeriodBytes], 'a period queued behind the one that ended went with it');

        $preview = $restarted->preview($ended);
        self::assertSame([SubscriptionStatus::Active, null, null, 7 * 24], [$preview->status, $preview->starts_at, $preview->expires_at, $preview->duration_hours], 'shown before it is paid: running again, its clock waiting');
    }

    public function testExtraDevicesRaiseTheIpLimitAndARefundTakesThemBackWhileTheirPeriodLasts(): void
    {
        $row = self::row(usedGb: 5, daysLeft: 10)->forceFill(['ip_limit' => 2]);

        $extra = ServiceTerms::extra($row, days: 0, bytes: 0, devices: 3);
        self::assertSame(5, $extra->ipLimit, 'two and three more');
        self::assertSame(['2026-09-30 12:00:00', 30 * Traffic::GIGABYTE, 720], [$extra->expiry->deadline()?->format('Y-m-d H:i:s'), $extra->totalBytes, $extra->durationHours], 'its term and quota as they were');
        $given = $extra->preview($row);
        self::assertSame(5, $given->ip_limit, 'shown before it is paid');
        self::assertSame(0, ServiceTerms::extra(self::row(usedGb: 5, daysLeft: 10)->forceFill(['ip_limit' => 0]), days: 0, bytes: 0, devices: 3)->ipLimit, 'unlimited stays unlimited');
        self::assertSame(2, ServiceTerms::extra($row, days: 7, bytes: 0)->ipLimit, 'days leave the devices alone');
        self::assertSame(2, ServiceTerms::extra(self::row(usedGb: 5, daysLeft: -1, status: SubscriptionStatus::Expired)->forceFill(['ip_limit' => 2]), days: 7, bytes: 0)->ipLimit, 'and so do days that restart a term');

        $bought = now()->subHour();
        $back = ServiceTerms::takeBack($given, 0, 0, renewal: false, devices: 3, boughtAt: $bought);
        self::assertSame([2, '2026-09-30 12:00:00', 30 * Traffic::GIGABYTE], [$back->ipLimit, $back->expiry->deadline()?->format('Y-m-d H:i:s'), $back->totalBytes], 'back to two; its term and quota untouched');
        self::assertSame(1, ServiceTerms::takeBack($row, 0, 0, renewal: false, devices: 5, boughtAt: $bought)->ipLimit, 'never below one');
        self::assertSame(0, ServiceTerms::takeBack(self::row(usedGb: 5, daysLeft: 10)->forceFill(['ip_limit' => 0]), 0, 0, renewal: false, devices: 3)->ipLimit, 'unlimited stays unlimited');
        self::assertSame(2, ServiceTerms::takeBack($row, 7 * 24, 0, renewal: false)->ipLimit, "an extra's days taken back leave the devices alone");

        $reset = $given->replicate()->forceFill(['ip_limit' => 2, 'ip_limit_reset_at' => now()->subMinute()]);
        self::assertSame(2, ServiceTerms::takeBack($reset, 0, 0, renewal: false, devices: 3, boughtAt: $bought)->ipLimit, "the limit went back to a plan's since they were bought: they went with their period, nothing is taken");
        $boughtSince = $reset->replicate()->forceFill(['ip_limit' => 5]);
        self::assertSame(2, ServiceTerms::takeBack($boughtSince, 0, 0, renewal: false, devices: 3, boughtAt: now())->ipLimit, 'devices bought after it come off');
    }

    public function testDevicesBoughtWhileARenewalIsQueuedEndWithThePeriodInUse(): void
    {
        $queued = static fn(?int $next): Subscription => self::row(usedGb: 20, daysLeft: 35, limitGb: 60)->forceFill(['ip_limit' => 2, 'period_ends_at' => now()->addDays(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE, 'next_period_ip_limit' => $next]);

        $extra = ServiceTerms::extra($queued(1), 0, 0, devices: 3);
        self::assertSame([5, 1, '2026-09-25 12:00:00'], [$extra->ipLimit, $extra->nextPeriodIpLimit, $extra->periodEndsAt?->format('Y-m-d H:i:s')], 'five until the period ends, then the renewal\'s one');
        self::assertSame(2, ServiceTerms::extra($queued(null), 0, 0, devices: 3)->nextPeriodIpLimit, 'a period queued for its traffic alone keeps the two it was to have');
        self::assertSame(['2026-09-28 12:00:00', 1], [ServiceTerms::extra($queued(1), 3, 0)->periodEndsAt?->format('Y-m-d H:i:s'), ServiceTerms::extra($queued(1), 3, 0)->nextPeriodIpLimit], 'days bought lengthen the period in use, its devices with it');

        $begun = $queued(1)->forceFill(['period_ends_at' => now()->subMinute()]);
        self::assertSame([5, 4], [ServiceTerms::extra($begun, 0, 0, devices: 3)->ipLimit, ServiceTerms::extra($begun, 0, 0, devices: 3)->nextPeriodIpLimit], 'a period whose end has passed has begun: they go onto its limit');

        $taken = ServiceTerms::takeBack($extra->preview($queued(1)), 0, 0, renewal: false, devices: 3, boughtAt: now());
        self::assertSame([2, 1, '2026-09-25 12:00:00'], [$taken->ipLimit, $taken->nextPeriodIpLimit, $taken->periodEndsAt?->format('Y-m-d H:i:s')], 'refunded, the period in use goes back to two; the renewal\'s one still waits');

        $grant = ServiceTerms::grant($queued(1), 2, 0);
        self::assertSame(['2026-09-27 12:00:00', 1], [$grant->periodEndsAt?->format('Y-m-d H:i:s'), $grant->nextPeriodIpLimit], 'days given move the devices\' end too');
    }

    public function testTheQueuedPeriodBringsItsDevicesWhenItBegins(): void
    {
        $due = static fn(?int $nextBytes, ?int $nextIp, int $usedGb = 24): Subscription => self::row(usedGb: $usedGb, daysLeft: 30, limitGb: 60)->forceFill(['ip_limit' => 5, 'period_ends_at' => now()->subMinute(), 'next_period_bytes' => $nextBytes, 'next_period_ip_limit' => $nextIp]);

        $both = ServiceTerms::nextPeriod($due(30 * Traffic::GIGABYTE, 2));
        self::assertSame([30 * Traffic::GIGABYTE, true, 2], [$both?->totalBytes, $both?->resetCounters, $both?->ipLimit]);
        $devices = ServiceTerms::nextPeriod($due(null, 2));
        self::assertSame([60 * Traffic::GIGABYTE, false, 2], [$devices?->totalBytes, $devices?->resetCounters, $devices?->ipLimit], 'queued for its devices alone: the traffic as it is');
        self::assertSame([60 * Traffic::GIGABYTE, false, 2], [ServiceTerms::nextPeriod($due(30 * Traffic::GIGABYTE, 2, usedGb: 60))?->totalBytes, ServiceTerms::nextPeriod($due(30 * Traffic::GIGABYTE, 2, usedGb: 60))?->resetCounters, ServiceTerms::nextPeriod($due(30 * Traffic::GIGABYTE, 2, usedGb: 60))?->ipLimit], 'all used: nothing to cap, the devices still change');
        self::assertNull(ServiceTerms::nextPeriod($due(null, 5)), 'the same limit: nothing for the panel');

        self::assertSame(
            ['period_ends_at' => null, 'next_period_bytes' => null, 'next_period_ip_limit' => null, 'ip_limit' => 2, 'ip_limit_reset_at' => '2026-09-20 11:59:00'],
            array_replace(ServiceTerms::periodOver($due(null, 2)), ['ip_limit_reset_at' => ServiceTerms::periodOver($due(null, 2))['ip_limit_reset_at']?->format('Y-m-d H:i:s')]),
            'the renewal\'s limit, in force from the moment the period ended: the devices bought for it went with it',
        );
        self::assertSame(['period_ends_at' => null, 'next_period_bytes' => null, 'next_period_ip_limit' => null], ServiceTerms::periodOver($due(30 * Traffic::GIGABYTE, null)), 'a period queued before 0.4.0 leaves the limit as it is');
    }

    public function testARefundedRenewalPutsBackTheDeviceLimitItSet(): void
    {
        $queued = ServiceTerms::takeBack(self::row(usedGb: 20, daysLeft: 35)->forceFill(['ip_limit' => 5, 'period_ends_at' => now()->addDays(5), 'next_period_ip_limit' => 2]), 720, 0, renewal: true);
        self::assertSame([5, null, null], [$queued->limitPutBack(2, null, true)->ipLimit, $queued->limitPutBack(2, null, true)->nextPeriodIpLimit, $queued->limitPutBack(2, null, true)->periodEndsAt], 'the limit it queued dropped, and the period with it');
        self::assertSame([3, '2026-09-25 12:00:00'], [$queued->limitPutBack(2, 3, true)->nextPeriodIpLimit, $queued->limitPutBack(2, 3, true)->periodEndsAt?->format('Y-m-d H:i:s')], 'the one queued before it back');
        self::assertSame(2, $queued->limitPutBack(4, null, true)->nextPeriodIpLimit, 'a limit queued since by another stays');

        $atOnce = ServiceTerms::takeBack(self::row(usedGb: 20, daysLeft: 35)->forceFill(['ip_limit' => 2, 'ip_limit_reset_at' => now()]), 720, 0, renewal: true);
        self::assertSame(5, $atOnce->limitPutBack(2, 5, false)->ipLimit, 'the limit it set at once back to the five it replaced');
        self::assertSame(4, $atOnce->limitPutBack(3, 5, false)->ipLimit, 'one changed since — a device taken back, the panel — keeps the change on the five it replaced');
        self::assertSame(['ip_limit' => 2], array_intersect_key($atOnce->columns(), ['ip_limit' => 0, 'ip_limit_reset_at' => 0]), 'nothing put back: the moment its limit went back stays');

        $reset = $atOnce->limitPutBack(2, 5, false, reset: true, resetWas: null);
        self::assertSame(['ip_limit' => 5, 'ip_limit_reset_at' => null], array_intersect_key($reset->columns(), ['ip_limit' => 0, 'ip_limit_reset_at' => 0]), 'it put the limit back to its plan\'s: when that had last happened before it back too — never —, so the devices bought before it count again');
        $earlier = Carbon::parse('2026-09-01 12:00:00');
        self::assertSame('2026-09-01 12:00:00', $atOnce->limitPutBack(2, 5, false, reset: true, resetWas: $earlier)->columns()['ip_limit_reset_at']?->format('Y-m-d H:i:s'));
    }

    public function testARenewalsChangeOfTheDeviceLimitIsUndoneOnTheLimitAsItIsNow(): void
    {
        self::assertSame(5, ServiceTerms::limitUndone(2, 2, 5), 'unchanged since: what it replaced');
        self::assertSame(6, ServiceTerms::limitUndone(3, 2, 5), 'a device bought since stays on top');
        self::assertSame(1, ServiceTerms::limitUndone(1, 4, 2), 'never below one');
        self::assertSame(0, ServiceTerms::limitUndone(3, 2, 0), 'unlimited before: unlimited again');
        self::assertSame([3, 4], [ServiceTerms::limitUndone(0, 0, 3), ServiceTerms::limitUndone(4, 0, 3)], 'one it made unlimited back to its three — unless a limit was set since');
        self::assertSame(0, ServiceTerms::limitUndone(0, 2, 3), 'made unlimited since: stays so');
    }

    public function testARenewalKeepsWhatItStartsAfreshAndWhatItGivesWhereThereWasNone(): void
    {
        $kept = static function (Subscription $row, RenewalMethod $method, bool $carry = false): array {
            $given = ServiceTerms::renewal($row, self::plan()->package(), $carry, $method)->given($row);

            return array_keys(array_filter(array_intersect_key($given, array_flip(['gave_term', 'gave_quota', 'reset_term', 'reset_traffic', 'reset_ip_limit']))));
        };
        $running = static fn(): Subscription => self::row(usedGb: 20, daysLeft: 5);
        $endedByTime = static fn(): Subscription => self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired);

        self::assertSame([], $kept($running(), RenewalMethod::Extend), 'an extension carries all of it');
        self::assertSame(['reset_term', 'reset_ip_limit'], $kept($running(), RenewalMethod::ResetTime), 'a reset of the time: the term and the devices of its period');
        self::assertSame(['reset_traffic'], $kept($running(), RenewalMethod::ResetTraffic), 'a reset of the traffic: the count, the period goes on');
        self::assertSame([], $kept(self::row(usedGb: 0, daysLeft: null), RenewalMethod::Extend), 'one waiting for its first connection: a longer term, its devices on top');
        self::assertSame(['reset_term', 'reset_traffic', 'reset_ip_limit'], $kept($endedByTime(), RenewalMethod::Extend), 'ended by time, the traffic not carried: everything afresh');
        self::assertSame(['reset_term', 'reset_ip_limit'], $kept($endedByTime(), RenewalMethod::Extend, carry: true), 'the traffic left carried');
        self::assertSame(['reset_ip_limit'], $kept(self::row(usedGb: 30, daysLeft: 5, status: SubscriptionStatus::Expired), RenewalMethod::Extend), 'ended by traffic: its period over, its days carried');
        $endless = self::row(usedGb: 5, daysLeft: null, limitGb: 0)->forceFill(['duration_hours' => 0]);
        self::assertSame(['gave_term', 'gave_quota', 'reset_term', 'reset_traffic', 'reset_ip_limit'], $kept($endless, RenewalMethod::Extend), 'never ending and unlimited: a term and a quota where it had none');
    }

    public function testARenewalOfAPeriodWhoseEndPassedGoesOnFromItAsBegun(): void
    {
        // Renewed early: 35 days left, a period queued to end five minutes ago with 30 GB and three devices (one bought for
        // it once its end had passed); NextPeriodTask has not run yet.
        $due = self::row(usedGb: 10, daysLeft: 35, limitGb: 60)->forceFill(['ip_limit' => 3, 'period_ends_at' => now()->subMinutes(5), 'next_period_bytes' => 30 * Traffic::GIGABYTE, 'next_period_ip_limit' => 3, 'ip_limit_reset_at' => null]);

        $terms = ServiceTerms::renewal($due, self::plan()->package(), false);

        self::assertSame([3, 2, '2026-10-25 12:00:00'], [$terms->ipLimit, $terms->nextPeriodIpLimit, $terms->periodEndsAt?->format('Y-m-d H:i:s')], 'the period begun goes on with its three to the deadline; the plan\'s two wait behind it');
        self::assertSame([60 * Traffic::GIGABYTE, 30 * Traffic::GIGABYTE], [$terms->totalBytes - $due->usedBytes(), $terms->nextPeriodBytes], 'the 50 GB left capped at the period\'s 30, the plan\'s 30 on top and queued at the deadline');
        self::assertSame('2026-09-20 11:55:00', $terms->limitResetAt?->format('Y-m-d H:i:s'), 'its limit went back to the queued one when it began');
        $given = $terms->given($due);
        self::assertSame([2, null, true, false], [$given['given_ip_limit'], $given['ip_limit_was'], $given['ip_limit_queued'], $given['reset_ip_limit']], 'queued, in place of nothing queued');
    }

    public function testATakeBackOfARenewalThatGaveATermOrAQuotaWhereThereWasNoneTakesThemAway(): void
    {
        $renewed = self::row(usedGb: 0, daysLeft: null)->forceFill(['ip_limit' => 2]);

        $back = ServiceTerms::takeBack($renewed, 720, 30 * Traffic::GIGABYTE, renewal: true, noTerm: true, noQuota: true);

        self::assertSame([null, null, 0, 0], [$back->expiry->deadline(), $back->expiry->pendingSeconds(), $back->durationHours, $back->totalBytes], 'never ending and unlimited again');
        self::assertTrue($back->changes($renewed));
        self::assertFalse(ServiceTerms::takeBack($renewed, 0, 0, renewal: true)->changes($renewed), 'nothing taken: nothing changes');
    }

    public function testAnHourlyPlanIsCountedByTheHour(): void
    {
        $plan = self::plan(['duration_hours' => 6]);

        $running = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), $plan->package(), carryTraffic: false);
        self::assertSame(['2026-09-25 18:00:00', 720 + 6], [$running->expiry->deadline()?->format('Y-m-d H:i:s'), $running->durationHours], 'six hours onto its deadline');
        self::assertSame((720 + 6) * 3600, ServiceTerms::renewal(self::row(usedGb: 0, daysLeft: null), $plan->package(), carryTraffic: false)->expiry->pendingSeconds(), 'a term still waiting grows by six hours');
        $ended = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired), $plan->package(), carryTraffic: false);
        self::assertSame([null, 6 * 3600, 6], [$ended->expiry->deadline(), $ended->expiry->pendingSeconds(), $ended->durationHours], 'six hours from its next connection');

        $short = self::row(usedGb: 1, daysLeft: null)->forceFill(['duration_hours' => 6, 'expires_at' => now()->addHours(2), 'starts_at' => now()->subHours(4)]);
        $granted = ServiceTerms::grant($short, days: 3, bytes: 0);
        self::assertSame(['2026-09-23 14:00:00', 6 + 72], [$granted->expiry->deadline()?->format('Y-m-d H:i:s'), $granted->durationHours], 'a grant gives an hourly service its days in hours');

        $starting = self::row(usedGb: 0, daysLeft: null)->forceFill(['duration_hours' => 6]);
        self::assertEquals(now()->subHours(4), ServiceTerms::startedAt($starting, now()->addHours(2), now()), 'a clock the panel just started: counted back from its deadline by its hours');
        self::assertNull(ServiceTerms::startedAt($short->forceFill(['starts_at' => null]), now()->addHours(5), now()), 'a clock that ran already, its start unknown (added by its link): still unknown');
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
        $renewal = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: 5), self::plan()->package(), carryTraffic: false);
        self::assertSame([
            'duration_hours' => 60 * 24,
            'ip_limit' => 1,
            'period_ends_at' => '2026-09-25 12:00:00',
            'next_period_bytes' => 30 * Traffic::GIGABYTE,
            'next_period_ip_limit' => 2,
        ], array_replace($renewal->columns(), ['period_ends_at' => $renewal->periodEndsAt?->format('Y-m-d H:i:s')]), "the plan's IP limit once the period in use ends");

        $ended = ServiceTerms::renewal(self::row(usedGb: 20, daysLeft: -1, status: SubscriptionStatus::Expired), self::plan()->package(), carryTraffic: false);
        self::assertSame([2, null, '2026-09-20 12:00:00'], [$ended->columns()['ip_limit'], $ended->columns()['next_period_ip_limit'], $ended->columns()['ip_limit_reset_at']?->format('Y-m-d H:i:s')], 'an ended one takes it at once');

        $grant = ServiceTerms::grant(self::row(usedGb: 20, daysLeft: 5), 3, 0);
        self::assertSame(['duration_hours' => 720 + 3 * 24, 'ip_limit' => 1, 'period_ends_at' => null, 'next_period_bytes' => null, 'next_period_ip_limit' => null], $grant->columns(), "a grant keeps the service's own IP limit");
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
        $renewal = static fn(Plan $plan, bool $carry): \Closure => static fn(Subscription $row): ServiceTerms => ServiceTerms::renewal($row, $plan->package(), $carry);
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
            $terms = $give($row());
            $back = ServiceTerms::takeBack($terms->preview($row()), $hours, $gb * Traffic::GIGABYTE, $isRenewal);
            if ($isRenewal) {
                $given = $terms->given($row());
                $back = $back->limitPutBack($given['given_ip_limit'], $given['ip_limit_was'], $given['ip_limit_queued']);
            }

            $was = $row();
            self::assertSame(
                [$was->expires_at?->format('Y-m-d H:i:s'), ServiceTerms::expiryOf($was)->pendingSeconds(), 720, 30 * Traffic::GIGABYTE, 1, null, null, null],
                [$back->expiry->deadline()?->format('Y-m-d H:i:s'), $back->expiry->pendingSeconds(), $back->durationHours, $back->totalBytes, $back->ipLimit, $back->periodEndsAt, $back->nextPeriodBytes, $back->nextPeriodIpLimit],
                $case,
            );
        }
    }

    public function testATakeBackEndsAServiceThatHadEndedBeforeIt(): void
    {
        $ended = self::row(usedGb: 30, daysLeft: -1, status: SubscriptionStatus::Expired);

        $renewed = ServiceTerms::takeBack(ServiceTerms::renewal($ended, self::plan()->package(), carryTraffic: false)->preview($ended), 720, 30 * Traffic::GIGABYTE, renewal: true);
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
