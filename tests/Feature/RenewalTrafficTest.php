<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Tasks\NextPeriodTask;
use App\Modules\Telegram\Handlers\SubscriptionHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Fakes\FakeProvider;

/**
 * What a renewal does with the traffic a period leaves unused — the admin's call. Carried, it is added to
 * the renewed period. Not carried, the renewal still adds the plan's traffic on top at once (nothing is
 * taken away early), and what the paid period leaves unused goes when it ends: from then on at most the
 * renewed traffic remains, counted afresh — unless nothing is left to cap. The days left always carry.
 */
final class RenewalTrafficTest extends BotTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->withoutQr();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testCarriedTheTrafficLeftIsAddedToTheRenewedPeriod(): void
    {
        $this->botSettings('renewal', ['carry_traffic' => true]);
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);

        $order = $this->renew($subscription);

        self::assertSame(60 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes, '30 of this period and 30 of the plan');
        self::assertSame(40 * Traffic::GIGABYTE, $subscription->remainingBytes(), 'the 10 left, carried, and the plan\'s 30');
        self::assertSame([], FakeProvider::$trafficReset, 'the count goes on');
        self::assertNull($subscription->period_ends_at, 'nothing to take away later');
        self::assertSame('2026-10-25', $subscription->expires_at?->format('Y-m-d'), 'the days left carry too');

        self::assertSame(self::text(BotText::Renewed, [
            'client' => 'ali_1',
            'plan' => $this->plan->name,
            'expires' => Messages::expiry($subscription->expires_at, $subscription->duration_days),
            'remaining' => Messages::bytes(40 * Traffic::GIGABYTE),
            'leftover' => self::text(BotText::RenewalLeftoverCarried),
        ]), $this->service(ServiceCard::class)->settledText($order), 'the renewal message says the traffic left went along');
    }

    public function testNotCarriedWhatThePaidPeriodLeavesUnusedGoesWhenItEnds(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);

        $this->renew($subscription);

        // At once: the plan's traffic on top, nothing taken away — the 10 left are still usable.
        self::assertSame(60 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes);
        self::assertSame(40 * Traffic::GIGABYTE, $subscription->remainingBytes());
        self::assertSame(['2026-09-25 12:00:00', 30 * Traffic::GIGABYTE], [$subscription->period_ends_at?->format('Y-m-d H:i:s'), $subscription->next_period_bytes]);

        $this->nextPeriods();
        self::assertSame(60 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes, 'not before the period ends');

        // The customer uses 4 more before it ends; 6 of this period are then left unused and go.
        $this->panelSays('ali_1', usedGb: 24, limitGb: 60);
        Carbon::setTestNow('2026-09-25 12:05:00');
        $this->nextPeriods();

        $subscription->refresh();
        self::assertSame(30 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes, 'the renewed period\'s traffic, counted afresh');
        self::assertSame(0, $subscription->usedBytes());
        self::assertSame(['ali_1'], FakeProvider::$trafficReset);
        self::assertSame(30 * Traffic::GIGABYTE, FakeProvider::lastUpdate('ali_1')->totalBytes);
        self::assertNull($subscription->period_ends_at);
        self::assertNull($subscription->next_period_bytes);
    }

    public function testACustomerWhoDippedIntoTheRenewedTrafficKeepsWhatIsLeftOfIt(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $this->renew($subscription);

        // 15 more: the 10 of this period and 5 of the renewed 30.
        $this->panelSays('ali_1', usedGb: 35, limitGb: 60);
        Carbon::setTestNow('2026-09-26 12:00:00');
        $this->nextPeriods();

        self::assertSame(25 * Traffic::GIGABYTE, $subscription->refresh()->remainingBytes(), 'nothing of this period was left to take away');
    }

    public function testAServiceThatUsedEverythingHasNothingToCapAndTheQueuedPeriodJustGoes(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $this->renew($subscription);
        $this->panelSays('ali_1', usedGb: 60, limitGb: 60);
        Carbon::setTestNow('2026-09-25 12:05:00');

        $this->nextPeriods();

        $subscription->refresh();
        self::assertSame([null, null], [$subscription->period_ends_at, $subscription->next_period_bytes]);
        self::assertSame([[], ['ali_1']], [FakeProvider::$trafficReset, FakeProvider::updatedNames()], 'the panel is told nothing more than the renewal');
        self::assertSame(SubscriptionStatus::Expired, $subscription->status, 'used up, as its panel says');
    }

    public function testAServiceWhoseClientIsGoneHasNothingToCap(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $this->renew($subscription);
        unset(FakeProvider::$clients[$this->server->id]['ali_1']); // removed on the panel by hand
        Carbon::setTestNow('2026-09-25 12:05:00');

        $this->nextPeriods();

        $subscription->refresh();
        self::assertSame([null, null], [$subscription->period_ends_at, $subscription->next_period_bytes]);
        self::assertSame([[], ['ali_1']], [FakeProvider::$trafficReset, FakeProvider::updatedNames()]);
        self::assertSame(SubscriptionStatus::Deleted, $subscription->status, 'the panel no longer has it');
    }

    public function testThePeriodsWaitingStartAtTheNextRunWhenThisOnesTimeIsUp(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $this->renew($subscription);
        Carbon::setTestNow('2026-09-25 12:05:00');

        $this->withTheTurnOver($this->nextPeriods(...));
        self::assertNotNull($subscription->refresh()->period_ends_at, 'the run had no time left: still due');

        $this->nextPeriods();
        self::assertNull($subscription->refresh()->period_ends_at);
    }

    public function testAServiceThatBreaksOrIsGoneStopsNoOthersPeriod(): void
    {
        $logs = $this->logs();
        $first = $this->running('ali_1', usedGb: 20, daysLeft: 3);
        $gone = $this->running('ali_2', usedGb: 20, daysLeft: 4);
        $broken = $this->running('ali_3', usedGb: 20, daysLeft: 5);
        $last = $this->running('ali_4', usedGb: 20, daysLeft: 6);
        foreach ([$first, $gone, $broken, $last] as $subscription) {
            $this->renew($subscription);
        }
        Carbon::setTestNow('2026-09-26 12:05:00');
        // Support deletes the second while the run is at the first; the third breaks on a fault of the shop's own.
        $broke = false;
        FakeProvider::$onCall = static function (int $server, string $call) use ($gone, &$broke): void {
            if ($call === 'findClient') {
                Subscription::query()->whereKey($gone->id)->delete();
            }
            if ($call === 'resetClientTraffic' && FakeProvider::$trafficReset === ['ali_1'] && !$broke) {
                $broke = true;

                throw new \RuntimeException('a fault of the shop\'s own');
            }
        };

        $this->nextPeriods();

        self::assertSame(['ali_1', 'ali_4'], FakeProvider::$trafficReset, 'the fourth started its period all the same');
        self::assertNull($last->refresh()->period_ends_at);
        self::assertNotNull($broken->refresh()->period_ends_at, 'the broken one still due, for the next run');
        self::assertTrue($logs->hasErrorThatContains('broke'), 'the fault logged');
    }

    public function testARenewalQueuedBehindAnotherAddsToIt(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);

        $this->renew($subscription);
        $this->renew($subscription);

        self::assertSame(90 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes);
        self::assertSame(['2026-09-25 12:00:00', 60 * Traffic::GIGABYTE], [$subscription->period_ends_at?->format('Y-m-d H:i:s'), $subscription->next_period_bytes], 'the first period still ends when it did');
        self::assertSame('2026-11-24', $subscription->expires_at?->format('Y-m-d'), 'both renewals\' days on top');
    }

    public function testAnExpiredServiceStartsAFreshCountWithWhatWasLeftOnlyWhenCarried(): void
    {
        $expired = fn(string $name): Subscription => $this->running($name, usedGb: 20, daysLeft: 0, overrides: ['expires_at' => now()->subDay(), 'status' => SubscriptionStatus::Expired]);

        $reset = $expired('ali_1');
        $this->renew($reset);
        self::assertSame([30 * Traffic::GIGABYTE, 0], [$reset->traffic_limit_bytes, $reset->usedBytes()], 'the plan\'s traffic, what was left gone with the period');

        $this->botSettings('renewal', ['carry_traffic' => true]);
        $carried = $expired('ali_2');
        $this->renew($carried);
        self::assertSame([40 * Traffic::GIGABYTE, 0], [$carried->traffic_limit_bytes, $carried->usedBytes()], 'the 10 left carried into a fresh count');
        self::assertSame(['ali_1', 'ali_2'], FakeProvider::$trafficReset);
        self::assertNull($carried->period_ends_at);
    }

    public function testAnUnlimitedPlanMakesTheServiceUnlimitedWithNothingQueued(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $unlimited = $this->plan(['name' => 'نامحدود', 'traffic_gb' => 0]);

        $this->renew($subscription, $unlimited);

        self::assertSame(0, $subscription->traffic_limit_bytes);
        self::assertNull($subscription->period_ends_at);
    }

    public function testTheServiceScreenSaysWhatGoesAndWhen(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $this->renew($subscription);

        $this->send($this->tap(SubscriptionHandler::serviceCallback($subscription->id)));

        self::assertStringContainsString(self::text(BotText::RenewalLeftoverUntil, [
            'expiring' => Messages::bytes(10 * Traffic::GIGABYTE),
            'date' => Persian::date(Carbon::parse('2026-09-25 12:00:00')),
            'next' => Messages::bytes(30 * Traffic::GIGABYTE),
        ]), $this->said()[0]);
    }

    public function testAPanelOutOfReachWhenThePeriodEndsIsTriedAgain(): void
    {
        $subscription = $this->running('ali_1', usedGb: 20, daysLeft: 5);
        $this->renew($subscription);
        Carbon::setTestNow('2026-09-25 12:05:00');
        FakeProvider::$down = [$this->server->id];

        $this->nextPeriods();
        self::assertNotNull($subscription->refresh()->period_ends_at, 'still due');
        self::assertSame(60 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes, 'the customer keeps what they had meanwhile');

        // The panel is left alone a while (Server::BACKOFF_MINUTES): back meanwhile, it is not asked yet.
        FakeProvider::$down = [];
        $this->nextPeriods();
        self::assertSame(60 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes);

        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->nextPeriods();
        self::assertSame(30 * Traffic::GIGABYTE, $subscription->refresh()->traffic_limit_bytes);
        self::assertNull($subscription->period_ends_at);
    }

    /**
     * A service of Ali's on the 30 GB plan with `usedGb` used and `daysLeft` days to go — the panel says the same.
     *
     * @param array<string, mixed> $overrides
     */
    private function running(string $name, int $usedGb, int $daysLeft, array $overrides = []): Subscription
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, $name, $overrides + [
            'download_bytes' => $usedGb * Traffic::GIGABYTE,
            'expires_at' => now()->addDays($daysLeft),
        ]);
        FakeProvider::mirror($subscription);

        return $subscription;
    }

    /** The customer used more since the shop last asked: the panel has `usedGb` of `limitGb`, the renewed deadline unchanged. */
    private function panelSays(string $name, int $usedGb, int $limitGb): void
    {
        FakeProvider::put($this->server, new ClientInfo(
            name: $name,
            enabled: true,
            downloadBytes: $usedGb * Traffic::GIGABYTE,
            totalBytes: $limitGb * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-10-25 12:00:00')),
            subscriptionUrl: "https://fake.test/sub/{$name}",
        ));
    }

    private function nextPeriods(): void
    {
        $this->service(NextPeriodTask::class)->run();
    }
}
