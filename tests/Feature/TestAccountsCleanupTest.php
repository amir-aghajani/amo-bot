<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Subscriptions\Tasks\CleanupServicesTask;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The clean-up of free test accounts: a test that ended goes from its panel and the shop once its grace is over
 * (counted from when it ended), one never connected after the shop's days, one gone from its panel at once without
 * asking it — and its `test_accounts` row stays for the quota. A test its panel extended or first connected since the
 * last read is kept, so is one on a panel in backoff; a paid service is never touched, nothing goes while the clean-up
 * is off, and a dead process's claim is given back.
 */
final class TestAccountsCleanupTest extends DatabaseTestCase
{
    private Server $server;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->server = $this->sellingServer('آلمان', ['test_enabled' => true, 'test_on_hold' => false, 'test_duration_hours' => 2]);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->testsOn();
    }

    public function testAnEndedTestGoesOnceItsGraceIsOver(): void
    {
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);

        Carbon::setTestNow('2026-10-08 13:59:00');
        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($test->id)->exists(), 'a minute short of a day after it ended at 14:00');

        Carbon::setTestNow('2026-10-08 14:00:00');
        $this->cleanUp();
        self::assertFalse(Subscription::query()->whereKey($test->id)->exists());
        self::assertSame([['server' => $this->server->id, 'name' => 'test_ali_1']], FakeProvider::$deleted);
        $given = TestAccount::query()->sole();
        self::assertNull($given->subscription_id, 'the row stays for the quota');
        self::assertNotNull($given->issued_at);
    }

    public function testWithNoGraceItGoesAtOnceAndTrafficEndsItToo(): void
    {
        $this->testsOn(['test_cleanup_hours' => 0]);
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);
        $this->usedUp($test);

        $this->cleanUp();

        self::assertFalse(Subscription::query()->whereKey($test->id)->exists(), 'its traffic ran out');
    }

    public function testOneNeverConnectedGoesAfterTheShopsDays(): void
    {
        $this->server->forceFill(['test_on_hold' => true])->save();
        $this->testsOn(['test_unused_days' => 3]);
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);

        Carbon::setTestNow('2026-10-10 11:59:00');
        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($test->id)->exists());

        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->cleanUp();
        self::assertFalse(Subscription::query()->whereKey($test->id)->exists());

        $this->testsOn(['test_unused_days' => 0]);
        $kept = $this->service(TestAccounts::class)->issue($this->admin(['telegram_id' => 7010]), $this->server->id);
        Carbon::setTestNow('2027-10-10 12:00:00');
        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($kept->id)->exists(), '0 days: never');
    }

    public function testOneGoneFromItsPanelGoesWithoutAskingItEvenInBackoff(): void
    {
        $this->testsOn(['test_cleanup_hours' => 0]);
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);
        $test->forceFill(['status' => SubscriptionStatus::Deleted])->save();
        $this->server->forceFill(['last_error' => 'down', 'last_checked_at' => now()])->save();

        $this->cleanUp();

        self::assertFalse(Subscription::query()->whereKey($test->id)->exists());
        self::assertSame([], FakeProvider::$deleted);
    }

    public function testAPanelInBackoffWaits(): void
    {
        $this->testsOn(['test_cleanup_hours' => 0]);
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);
        Carbon::setTestNow('2026-10-07 14:05:00');
        $this->server->forceFill(['last_error' => 'down', 'last_checked_at' => now()])->save();

        $this->cleanUp();

        self::assertTrue(Subscription::query()->whereKey($test->id)->exists(), 'tried on a later run');
    }

    public function testAPaidServiceIsNeverTouchedAndNothingGoesWhileItIsOff(): void
    {
        $this->testsOn(['test_cleanup_hours' => 0]);
        $paid = $this->subscription($this->ali, $this->plan([], $this->server), $this->server, 'ali_1', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]);
        $test = $this->service(TestAccounts::class)->issue($this->admin(['telegram_id' => 7010]), $this->server->id);
        $this->usedUp($test);

        $this->testsOn(['test_cleanup' => false, 'test_cleanup_hours' => 0]);
        $this->cleanUp();
        self::assertTrue(Subscription::query()->whereKey($test->id)->exists(), 'off: kept');

        $this->testsOn(['test_cleanup_hours' => 0]);
        $this->cleanUp();
        self::assertFalse(Subscription::query()->whereKey($test->id)->exists());
        self::assertTrue(Subscription::query()->whereKey($paid->id)->exists());
    }

    public function testATestItsPanelExtendedMeanwhileIsKept(): void
    {
        $this->testsOn(['test_cleanup_hours' => 0]);
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);
        Carbon::setTestNow('2026-10-07 14:10:00');
        // Support gave it two days on the panel after the shop last read it: the row still says it ended at 14:00.
        $test->forceFill(['expires_at' => now()->addDays(2)]);
        FakeProvider::mirror($test);

        $this->cleanUp();

        $kept = Subscription::query()->findOrFail($test->id);
        self::assertSame('2026-10-09T14:10:00+00:00', $kept->expires_at?->toIso8601String(), 'read from its panel');
        self::assertNull($kept->ended_at, 'running again');
        self::assertSame([], FakeProvider::$deleted);
    }

    public function testATestFirstConnectedSinceTheSyncIsKept(): void
    {
        $this->server->forceFill(['test_on_hold' => true])->save();
        $this->testsOn(['test_unused_days' => 3]);
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);
        Carbon::setTestNow('2026-10-10 12:00:00');
        // The customer connected a minute ago: the panel started its clock, the shop has not read it yet.
        $test->forceFill(['starts_at' => now()->subMinute(), 'expires_at' => now()->addHours(24)->subMinute()]);
        FakeProvider::mirror($test);

        $this->cleanUp();

        self::assertTrue(Subscription::query()->whereKey($test->id)->exists());
        self::assertSame([], FakeProvider::$deleted);
    }

    public function testAStoppedAgentsShopIsCleanedUpToo(): void
    {
        $bot = $this->agentBot();
        $test = CurrentBot::run($bot, function (): Subscription {
            $this->testsOn(['test_cleanup_hours' => 0]);

            return $this->service(TestAccounts::class)->issue($this->customer(['telegram_id' => 7003, 'username' => 'reza']), $this->server->id);
        });
        $bot->forceFill(['token' => null])->save();
        Carbon::setTestNow('2026-10-07 14:10:00');

        $this->cleanUp();

        self::assertFalse(Subscription::acrossShops()->whereKey($test->id)->exists(), 'its bot no longer runs, its tests still go');
    }

    public function testADeadProcesssClaimIsGivenBack(): void
    {
        $this->testsOn(['test_cleanup' => false]);
        TestAccount::query()->create(['user_id' => $this->ali->id, 'server_id' => $this->server->id, 'traffic_bytes' => 1, 'duration_hours' => 1, 'on_hold' => true]);

        Carbon::setTestNow('2026-10-07 12:29:00');
        $this->cleanUp();
        self::assertSame(1, TestAccount::query()->count(), 'maybe still being made');

        Carbon::setTestNow('2026-10-07 12:31:00');
        $this->cleanUp();
        self::assertSame(0, TestAccount::query()->count());
    }

    public function testSupportDeletingATestKeepsItsRow(): void
    {
        $test = $this->service(TestAccounts::class)->issue($this->ali, $this->server->id);

        $this->service(ProvisioningService::class)->delete($test);

        self::assertNull(TestAccount::query()->sole()->subscription_id);
    }

    /**
     * The shop's test accounts on, cleaned up a day after they end and a week after they were made unused — `$overrides`
     * on top.
     *
     * @param array<string, mixed> $overrides
     */
    private function testsOn(array $overrides = []): void
    {
        $this->botSettings('test_accounts', $overrides + [
            'test_enabled' => true,
            'test_per_user' => 1,
            'test_period_days' => 0,
            'test_daily_limit' => 0,
            'test_newcomers_only' => false,
            'test_cleanup' => true,
            'test_cleanup_hours' => 24,
            'test_unused_days' => 7,
        ]);
    }

    /** The test's traffic used up on its panel and as the last sync left the row. */
    private function usedUp(Subscription $test): void
    {
        $test->forceFill(['status' => SubscriptionStatus::Expired, 'download_bytes' => $test->traffic_limit_bytes])->save();
        FakeProvider::mirror($test);
    }

    private function cleanUp(): void
    {
        $this->service(CleanupServicesTask::class)->run();
    }
}
