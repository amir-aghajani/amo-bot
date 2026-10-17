<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\ServiceCleanup;
use App\Modules\Subscriptions\Tasks\CleanupServicesTask;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The automatic removal of ended services: off until the shop switches it on; then a service goes from its panel and the
 * shop so many hours after it ended — its deadline, or when the sync found its traffic used up —, one gone from its
 * panel without asking it. A service renewed meanwhile, switched off by support or with an order open stays; each shop
 * has its own rule; every screen can say when a service goes, and the run's list (asked of the database) agrees with it.
 */
final class ExpiredServicesCleanupTest extends DatabaseTestCase
{
    private Server $server;
    private User $ali;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->server = $this->sellingServer();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->plan = $this->plan([], $this->server);
    }

    public function testNothingGoesUntilTheShopSwitchesItOn(): void
    {
        $service = $this->endedService();

        Carbon::setTestNow('2027-10-07 12:00:00');
        $this->cleanUp();

        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());
        self::assertNull($this->service(ServiceCleanup::class)->removesAt($service), 'nothing to say on its screens either');
    }

    public function testAServiceGoesSoManyHoursAfterItsDeadline(): void
    {
        $this->removeAfter(12);
        $service = $this->endedService();
        $order = $this->buy($this->ali, $this->plan, $this->server);
        $order->forceFill(['subscription_id' => $service->id])->save();

        self::assertSame('2026-10-07T22:00:00+00:00', $this->service(ServiceCleanup::class)->removesAt($service)?->toIso8601String(), 'its deadline (10:00) and 12 hours');

        Carbon::setTestNow('2026-10-07 21:59:00');
        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists(), 'a minute short');

        Carbon::setTestNow('2026-10-07 22:00:00');
        $this->cleanUp();
        self::assertFalse(Subscription::query()->whereKey($service->id)->exists());
        self::assertSame([['server' => $this->server->id, 'name' => 'ali_9']], FakeProvider::$deleted);
        self::assertNull($order->refresh()->subscription_id, 'the order that sold it stays, for the record');
    }

    public function testATrafficEndCountsFromWhenTheSyncFoundIt(): void
    {
        $this->removeAfter(12);
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_9');
        $service->forceFill(['download_bytes' => $service->traffic_limit_bytes]);
        FakeProvider::mirror($service);

        Carbon::setTestNow('2026-10-07 13:00:00');
        $this->service(ProvisioningService::class)->syncServer($this->server);
        $service->refresh();
        self::assertSame(SubscriptionStatus::Expired, $service->status);
        self::assertSame('2026-10-07T13:00:00+00:00', $service->ended_at?->toIso8601String(), 'when the sync found it used up');

        Carbon::setTestNow('2026-10-08 00:59:00');
        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());

        Carbon::setTestNow('2026-10-08 01:00:00');
        $this->cleanUp();
        self::assertFalse(Subscription::query()->whereKey($service->id)->exists());
    }

    public function testAServiceRenewedMeanwhileStays(): void
    {
        $this->removeAfter(0);
        $service = $this->endedService();

        $this->renew($service);
        self::assertSame(SubscriptionStatus::Active, $service->status);
        self::assertNull($service->ended_at, 'it runs again');
        $this->cleanUp();

        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());
    }

    public function testAnOpenOrderKeepsItUntilItIsDone(): void
    {
        $this->removeAfter(0);
        $service = $this->endedService();
        $renewal = $this->service(OrderService::class)->createRenewal($this->ali, $service, $this->plan);

        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($service->id)->exists(), 'a renewal waits for its payment');
        self::assertNotContains($service->id, $this->service(ServiceCleanup::class)->due(now())->modelKeys(), 'nor takes a place in the run\'s batch');

        $renewal->forceFill(['status' => OrderStatus::Cancelled])->save();
        $this->cleanUp();
        self::assertFalse(Subscription::query()->whereKey($service->id)->exists());
    }

    public function testARenewalOrderedWhileThePanelDeletesTheClientKeepsTheService(): void
    {
        $this->removeAfter(0);
        $service = $this->endedService();
        $renewal = null;
        FakeProvider::$onCall = function (int $server, string $call) use ($service, &$renewal): void {
            if ($call === 'deleteClient' && $renewal === null) {
                $renewal = $this->service(OrderService::class)->createRenewal($this->ali, $service, $this->plan);
            }
        };

        $this->cleanUp();

        self::assertNotNull($renewal);
        self::assertSame(SubscriptionStatus::Deleted, $service->refresh()->status, 'kept for its renewal, as its panel has it');
        self::assertSame($service->id, $renewal->refresh()->subscription_id, 'the order still names the service it renews');
    }

    public function testSwitchedOffBySupportItStays(): void
    {
        $this->removeAfter(0);
        $service = $this->endedService(['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]);

        $this->cleanUp();

        self::assertTrue(Subscription::query()->whereKey($service->id)->exists());
        self::assertNull($this->service(ServiceCleanup::class)->removesAt($service));
    }

    public function testOneGoneFromItsPanelGoesWithoutAskingIt(): void
    {
        $this->removeAfter(1);
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_9', ['status' => SubscriptionStatus::Deleted, 'ended_at' => now()]);
        $this->server->forceFill(['last_error' => 'down', 'last_checked_at' => now()->addHour()])->save();

        Carbon::setTestNow('2026-10-07 13:00:00');
        $this->cleanUp();

        self::assertFalse(Subscription::query()->whereKey($service->id)->exists());
        self::assertSame([], FakeProvider::$deleted);
    }

    public function testEachShopHasItsOwnRule(): void
    {
        $this->removeAfter(0);
        $mine = $this->endedService();
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, function (): Subscription {
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);

            return $this->endedService(['user_id' => $reza->id, 'plan_id' => $this->plan(['traffic_gb' => 10], $this->server)->id], 'reza_1');
        });

        $this->cleanUp();

        self::assertFalse(Subscription::query()->whereKey($mine->id)->exists());
        self::assertTrue(Subscription::acrossShops()->whereKey($theirs->id)->exists(), 'the agent keeps theirs: their rule is off');
    }

    public function testWithNothingSayingWhenItEndedItCountsFromItsRowsLastChange(): void
    {
        $this->removeAfter(2);
        // Marked ended with no `ended_at` and its deadline still ahead (its traffic ran out): its row's last change.
        $service = $this->subscription($this->ali, $this->plan, $this->server, 'ali_9', ['status' => SubscriptionStatus::Expired]);

        self::assertSame('2026-10-07T14:00:00+00:00', $this->service(ServiceCleanup::class)->removesAt($service)?->toIso8601String());
    }

    public function testTheRunsListAgreesWithWhatEveryScreenSays(): void
    {
        $this->removeAfter(2);
        $this->botSettings('test_accounts', [
            'test_enabled' => true,
            'test_per_user' => 1,
            'test_period_days' => 0,
            'test_daily_limit' => 0,
            'test_newcomers_only' => false,
            'test_cleanup' => true,
            'test_cleanup_hours' => 1,
            'test_unused_days' => 1,
        ]);
        $test = ['plan_id' => null, 'is_test' => true];
        $services = [
            $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Expired, 'ended_at' => now()->subHour()]),
            // Past its deadline before a sync said so: from its deadline.
            $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['expires_at' => now()->subMinutes(30)]),
            // Ended by its traffic, with nothing saying when: from its row's last change (from its deadline once that passed).
            $this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['status' => SubscriptionStatus::Expired]),
            $this->subscription($this->ali, $this->plan, $this->server, 'ali_4', ['status' => SubscriptionStatus::Deleted, 'expires_at' => now()->subHours(3)]),
            $this->subscription($this->ali, $this->plan, $this->server, 'ali_5'),
            $this->subscription($this->ali, $this->plan, $this->server, 'ali_6', ['status' => SubscriptionStatus::Disabled, 'expires_at' => now()->subDay()]),
            $this->subscription($this->ali, $this->plan, $this->server, 'test_ali_1', $test + ['expires_at' => now()->addHour()]),
            $this->subscription($this->ali, $this->plan, $this->server, 'test_ali_2', $test + ['starts_at' => null, 'expires_at' => null, 'duration_hours' => 24]),
            $this->subscription($this->ali, $this->plan, $this->server, 'test_ali_3', $test + ['status' => SubscriptionStatus::Expired, 'ended_at' => now()]),
        ];
        $cleanup = $this->service(ServiceCleanup::class);

        $moments = ['2026-10-07 12:00:00', '2026-10-07 13:00:00', '2026-10-07 13:29:59', '2026-10-07 13:30:00', '2026-10-07 14:00:00', '2026-10-08 12:00:00', '2026-11-08 12:00:00'];
        foreach ($moments as $moment) {
            Carbon::setTestNow($moment);
            $due = $cleanup->due(now())->modelKeys();
            foreach ($services as $service) {
                $at = $cleanup->removesAt($service);
                self::assertSame($at !== null && $at->lte(now()), in_array($service->id, $due, true), "{$service->remote_name} at {$moment}");
            }
        }
    }

    /**
     * The shop's ended services removed `$hours` after they ended.
     */
    private function removeAfter(int $hours): void
    {
        $this->botSettings('expired_services', ['expired_cleanup' => true, 'expired_cleanup_hours' => $hours]);
    }

    /**
     * A service of Ali's whose deadline passed at 10:00, as the sync left it, its panel saying the same.
     *
     * @param array<string, mixed> $overrides
     */
    private function endedService(array $overrides = [], string $name = 'ali_9'): Subscription
    {
        $service = $this->subscription($this->ali, $this->plan, $this->server, $name, $overrides + [
            'status' => SubscriptionStatus::Expired,
            'starts_at' => now()->subDays(30)->subHours(2),
            'expires_at' => now()->subHours(2),
            'ended_at' => now()->subHours(2),
        ]);
        FakeProvider::mirror($service);

        return $service;
    }

    private function cleanUp(): void
    {
        $this->service(CleanupServicesTask::class)->run();
    }
}
