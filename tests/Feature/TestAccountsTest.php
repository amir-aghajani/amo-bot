<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\TestRefusal;
use App\Modules\Subscriptions\Exceptions\TestAccountRefusedException;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Subscriptions\Services\TestAccounts;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Free test accounts: what a test gets on its server (on hold or from the moment it is made), the quota — the switch,
 * newcomers only, a customer's tests for good or within a window, the shop's cap a day of its clock, the bot's admins
 * either way —, two taps taking one test, a panel failure giving the claim back (a client it made though it did not
 * answer kept instead), and an agent's shop drawing the test from its pool.
 */
final class TestAccountsTest extends DatabaseTestCase
{
    private Server $server;
    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->server = $this->sellingServer('آلمان', ['test_enabled' => true]);
        $this->ali = $this->customer(['username' => 'ali']);
        $this->testsOn();
    }

    public function testATestOnHoldWaitsForItsFirstConnection(): void
    {
        $test = $this->tests()->issue($this->ali, $this->server->id);

        $created = FakeProvider::$created[0];
        self::assertSame(['1'], $created['inbounds'], 'every inbound the server sells');
        self::assertSame('test_ali_1', $created['spec']->name);
        self::assertSame(500 * Traffic::MEGABYTE, $created['spec']->totalBytes);
        self::assertSame(24 * 3600, $created['spec']->expiry->pendingSeconds());
        self::assertTrue($test->is_test);
        self::assertNull($test->plan_id);
        self::assertTrue($test->awaitsFirstUse());
        self::assertSame([24, 500 * Traffic::MEGABYTE], [$test->duration_hours, $test->traffic_limit_bytes]);

        $given = TestAccount::query()->sole();
        self::assertSame([$this->ali->id, $this->server->id, $test->id, 24, true], [$given->user_id, $given->server_id, $given->subscription_id, $given->duration_hours, $given->on_hold]);
        self::assertNotNull($given->issued_at);
    }

    public function testATestNotOnHoldRunsFromTheMomentItIsMade(): void
    {
        $this->server->forceFill(['test_on_hold' => false, 'test_duration_hours' => 3, 'test_traffic_bytes' => Traffic::GIGABYTE])->save();

        $test = $this->tests()->issue($this->ali, $this->server->id);

        self::assertSame('2026-10-07T15:00:00+00:00', $test->expires_at?->toIso8601String());
        self::assertSame('2026-10-07T12:00:00+00:00', $test->starts_at?->toIso8601String());
        self::assertSame([3, Traffic::GIGABYTE], [$test->duration_hours, $test->traffic_limit_bytes]);
        self::assertFalse(TestAccount::query()->sole()->on_hold);
    }

    public function testACustomersQuotaForGood(): void
    {
        $this->tests()->issue($this->ali, $this->server->id);

        $refused = $this->refused(fn() => $this->tests()->offer($this->ali));
        self::assertSame(TestRefusal::Used, $refused->reason);
        self::assertNull($refused->nextAt, 'for good');
        self::assertSame(TestRefusal::Used, $this->refused(fn() => $this->tests()->issue($this->ali, $this->server->id))->reason);

        Carbon::setTestNow(now()->addYear());
        self::assertSame(TestRefusal::Used, $this->refused(fn() => $this->tests()->offer($this->ali))->reason, 'a year on, still used');
    }

    public function testACustomersQuotaWithinAWindow(): void
    {
        $this->testsOn(['test_per_user' => 2, 'test_period_days' => 7]);

        $this->tests()->issue($this->ali, $this->server->id);
        Carbon::setTestNow('2026-10-09 08:00:00');
        $this->tests()->issue($this->ali, $this->server->id);

        $refused = $this->refused(fn() => $this->tests()->issue($this->ali, $this->server->id));
        self::assertSame(TestRefusal::Used, $refused->reason);
        self::assertSame('2026-10-14T12:00:00+00:00', $refused->nextAt?->toIso8601String(), 'once the first leaves the window');

        Carbon::setTestNow('2026-10-14 12:00:01');
        $this->tests()->issue($this->ali, $this->server->id);
        self::assertSame(3, TestAccount::query()->count());
    }

    public function testNewcomersOnlyAndTheBotsAdminsEitherWay(): void
    {
        $this->testsOn(['test_newcomers_only' => true, 'test_per_user' => 1]);
        $this->buy($this->ali, $this->plan(['name' => 'تست رایگان', 'price' => '0.00'], $this->server), $this->server);
        self::assertCount(1, $this->tests()->offer($this->ali), 'a free plan makes no buyer');

        $this->buy($this->ali, $this->plan([], $this->server), $this->server);
        self::assertSame(TestRefusal::NewcomersOnly, $this->refused(fn() => $this->tests()->offer($this->ali))->reason);

        $boss = $this->admin(['telegram_id' => 7010, 'username' => 'boss']);
        $this->buy($boss, $this->plan([], $this->server), $this->server);
        $this->tests()->issue($boss, $this->server->id);
        $this->tests()->issue($boss, $this->server->id);
        self::assertSame(2, TestAccount::query()->where('user_id', $boss->id)->count(), 'no quota for the bot\'s admins');
    }

    public function testTheShopsCapADayOnItsClockCountsTheBotsAdminsWithoutRefusingThem(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            $this->testsOn(['test_daily_limit' => 1]);
            // 20:00 UTC is 23:30 in Tehran.
            Carbon::setTestNow('2026-10-07 20:00:00');
            $boss = $this->admin(['telegram_id' => 7010, 'username' => 'boss']);
            $this->tests()->issue($boss, $this->server->id);

            self::assertSame(TestRefusal::DailyLimit, $this->refused(fn() => $this->tests()->issue($this->ali, $this->server->id))->reason, 'the admin\'s test counts');
            $this->tests()->issue($boss, $this->server->id);
            self::assertSame(2, TestAccount::query()->count(), 'the cap never refuses an admin');

            // 21:00 UTC is 00:30 in Tehran: a new day for the shop, the same day in UTC.
            Carbon::setTestNow('2026-10-07 21:00:00');
            $this->tests()->issue($this->ali, $this->server->id);
            self::assertSame(3, TestAccount::query()->count());
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
            self::assertSame(TestRefusal::DailyLimit, $this->refused(fn() => $this->tests()->issue($reza, $this->server->id))->reason, 'the new day began at 20:30 UTC: Ali\'s test counts');
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testSwitchedOffOrNoServerGivesNone(): void
    {
        $this->server->forceFill(['test_enabled' => false])->save();
        self::assertSame(TestRefusal::NoServer, $this->refused(fn() => $this->tests()->offer($this->ali))->reason);

        $this->server->forceFill(['test_enabled' => true, 'is_active' => false])->save();
        self::assertSame(TestRefusal::NoServer, $this->refused(fn() => $this->tests()->issue($this->ali, $this->server->id))->reason, 'a server that cannot sell');

        $this->server->forceFill(['is_active' => true])->save();
        $this->testsOn(['test_enabled' => false]);
        self::assertSame(TestRefusal::Off, $this->refused(fn() => $this->tests()->offer($this->ali))->reason);
        self::assertSame([], FakeProvider::$created);
        self::assertSame(0, TestAccount::query()->count());
    }

    public function testTheOfferListsTheServersInTheirOrderWithTheirTerms(): void
    {
        $this->server->forceFill(['sort' => 2])->save();
        $france = $this->sellingServer('فرانسه', ['test_enabled' => true, 'sort' => 1, 'test_on_hold' => false, 'test_duration_hours' => 6]);
        $this->sellingServer('هلند');

        $offer = $this->tests()->offer($this->ali);

        self::assertSame([$france->id, $this->server->id], array_map(static fn(array $choice): int => $choice['server']->id, $offer));
        self::assertSame([6, false], [$offer[0]['terms']->hours, $offer[0]['terms']->onHold]);
    }

    public function testOfTwoTapsOneTakesTheTest(): void
    {
        $second = null;
        $this->whileListening('eloquent.created: ' . TestAccount::class, function () use (&$second): void {
            if ($second === null) {
                $second = $this->refused(fn() => $this->tests()->issue($this->ali, $this->server->id));
            }
        }, fn() => $this->tests()->issue($this->ali, $this->server->id));

        self::assertSame(TestRefusal::Used, $second?->reason);
        self::assertCount(1, FakeProvider::$created);
        self::assertSame(1, TestAccount::query()->count());
    }

    public function testAPanelFailureGivesTheClaimBack(): void
    {
        FakeProvider::$unreachable = true;
        try {
            $this->tests()->issue($this->ali, $this->server->id);
            self::fail('the panel failed');
        } catch (ProviderException) {
        }
        self::assertSame(0, TestAccount::query()->count());

        FakeProvider::$unreachable = false;
        $this->server->forceFill(['last_error' => null])->save();
        $this->tests()->issue($this->ali, $this->server->id);
        self::assertSame(1, TestAccount::query()->count(), 'the quota was given back');
    }

    public function testATestThePanelMadeThoughItsAnswerNeverCameIsGivenRatherThanMadeTwice(): void
    {
        // The panel makes the test's client, then its answer never comes.
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'createClient') {
                FakeProvider::put($this->server, new ClientInfo(name: 'test_ali_1', enabled: true, totalBytes: 500 * Traffic::MEGABYTE, expiry: Expiry::afterFirstUse(24 * 3600), subscriptionUrl: 'https://fake.test/sub/made'));
            }
        };
        FakeProvider::$failing = [$this->server->id => ['createClient' => ConnectionFailure::Timeout]];

        $test = $this->tests()->issue($this->ali, $this->server->id);

        self::assertSame(['test_ali_1', 'https://fake.test/sub/made'], [$test->remote_name, $test->subscription_url], 'the client it made, found by its name');
        self::assertSame(['test_ali_1'], array_keys(FakeProvider::$clients[$this->server->id]), 'no second client under the next name');
        self::assertSame(1, TestAccount::query()->count());
    }

    public function testAnAgentsShopDrawsTheTestFromItsPool(): void
    {
        $bot = $this->agentBot(traffic: 1);

        CurrentBot::run($bot, function () use ($bot): void {
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
            self::assertSame(TestRefusal::Off, $this->refused(fn() => $this->tests()->offer($reza))->reason, 'its own switch');

            $this->testsOn();
            $test = $this->tests()->issue($reza, $this->server->id);
            self::assertSame($bot->id, $test->bot_id);
            self::assertSame(Traffic::GIGABYTE - 500 * Traffic::MEGABYTE, $bot->trafficBalance());

            $sara = $this->customer(['telegram_id' => 7004, 'username' => 'sara']);
            $this->tests()->issue($sara, $this->server->id);
            self::assertSame(24 * Traffic::MEGABYTE, $bot->trafficBalance());

            $omid = $this->customer(['telegram_id' => 7005, 'username' => 'omid']);
            self::assertSame(TestRefusal::NoServer, $this->refused(fn() => $this->tests()->offer($omid))->reason, 'the pool is short');
        });
    }

    public function testAnAgentsPoolShortAtTheTapLeavesNothingBehind(): void
    {
        $bot = $this->agentBot(traffic: 1);

        CurrentBot::run($bot, function () use ($bot): void {
            $this->testsOn();
            $reza = $this->customer(['telegram_id' => 7003, 'username' => 'reza']);
            // The pool was enough when the server was offered, and is not by the time the test is written.
            FakeProvider::$onCall = function (int $server, string $call) use ($bot): void {
                if ($call === 'createClient') {
                    $this->traffic($bot, 0);
                }
            };

            self::assertSame(TestRefusal::NoServer, $this->refused(fn() => $this->tests()->issue($reza, $this->server->id))->reason);
            self::assertSame(0, TestAccount::query()->count(), 'the claim given back');
            self::assertSame([['server' => $this->server->id, 'name' => 'test_reza_1']], FakeProvider::$deleted, 'the client taken back off the panel');
            self::assertSame(0, $bot->trafficBalance(), 'nothing drawn');
        });
    }

    /**
     * The shop's test accounts switched on: one test a customer for good, no cap, anyone — `$overrides` on top.
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

    /** @param \Closure(): mixed $work */
    private function refused(\Closure $work): TestAccountRefusedException
    {
        try {
            $work();
        } catch (TestAccountRefusedException $e) {
            return $e;
        }

        self::fail('refused');
    }

    private function tests(): TestAccounts
    {
        return $this->service(TestAccounts::class);
    }
}
