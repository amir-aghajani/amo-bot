<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * The periodic sync: every running service (every bot's) read from its panel, one list per server — counters, a deadline
 * the panel started at the first connection, a client that is gone. A list missing a client never deletes it on its own
 * word, and a list that raced a change made elsewhere changes nothing of it. A panel in backoff is skipped; a run out of
 * time stops.
 */
final class SubscriptionSyncTest extends DatabaseTestCase
{
    private User $ali;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->ali = $this->customer(['username' => 'ali']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testEveryRunningServiceIsReadFromOneListOfItsPanel(): void
    {
        $used = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $started = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['starts_at' => null, 'expires_at' => null]);
        $ended = $this->subscription($this->ali, $this->plan, $this->server, 'ali_3', ['status' => SubscriptionStatus::Expired, 'download_bytes' => 5 * Traffic::GIGABYTE]);
        $this->onThePanel('ali_1', usedGb: 12, expiry: Expiry::at(new \DateTimeImmutable('2026-10-20 12:00:00')));
        $this->onThePanel('ali_2', usedGb: 1, expiry: Expiry::at(new \DateTimeImmutable('2026-10-19 12:00:00')));
        $this->onThePanel('ali_3', usedGb: 30, expiry: Expiry::at(new \DateTimeImmutable('2026-10-01 12:00:00')));

        $this->sync();

        self::assertSame(12 * Traffic::GIGABYTE, $used->refresh()->download_bytes);
        $started->refresh();
        self::assertSame('2026-10-19 12:00:00', $started->expires_at?->format('Y-m-d H:i:s'), 'the panel started the clock at the first connection');
        self::assertSame('2026-09-19 12:00:00', $started->starts_at?->format('Y-m-d H:i:s'));
        self::assertSame(5 * Traffic::GIGABYTE, $ended->refresh()->download_bytes, 'only running services are read');
    }

    public function testADeadlineThePanelSetsPastTheLastDayTheShopKeepsIsKeptAsThatDay(): void
    {
        // A long term first connected late, or a client extended on its panel by hand: MySQL keeps no TIMESTAMP past
        // January 2038, and one such row would stop the whole server's sync.
        $late = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['starts_at' => null, 'expires_at' => null, 'duration_hours' => 3650 * 24]);
        $next = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2');
        $this->onThePanel('ali_1', usedGb: 1, expiry: Expiry::at(new \DateTimeImmutable('2040-03-01 12:00:00')));
        $this->onThePanel('ali_2', usedGb: 4);

        $this->sync();

        self::assertEquals(LocalTime::last(), $late->refresh()->expires_at, 'the last moment the shop keeps');
        self::assertSame(SubscriptionStatus::Active, $late->status);
        self::assertSame(4 * Traffic::GIGABYTE, $next->refresh()->download_bytes, 'the sync goes on with the rest');

        $this->sync();
        self::assertEquals(LocalTime::last(), $late->refresh()->expires_at, 'read again, nothing moves');
    }

    public function testEveryBotsServicesOnAServerAreReadFromOneListOfItsPanel(): void
    {
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Subscription => $this->subscription($this->customer(['telegram_id' => 2001, 'username' => 'reza']), $this->plan(), $this->server, 'reza_1'));
        $ours = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 3);
        $this->onThePanel('reza_1', usedGb: 7);
        $lists = 0;
        FakeProvider::$whileListing = static function () use (&$lists): void {
            $lists++;
        };

        $this->sync();

        self::assertSame(1, $lists, 'one read of the panel for all of them');
        self::assertSame(3 * Traffic::GIGABYTE, $ours->refresh()->download_bytes);
        self::assertSame(7 * Traffic::GIGABYTE, Subscription::acrossShops()->findOrFail($theirs->id)->download_bytes, "the agent's bot's service too: a panel's clients are the server's");
    }

    public function testAClientTheListDoesNotShowIsAskedForOnItsOwn(): void
    {
        $gone = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');

        $this->sync();

        self::assertSame(SubscriptionStatus::Deleted, $gone->refresh()->status, 'the panel said so when asked for it');
    }

    public function testAListThatCameBackShortDeletesNothingThePanelStillHas(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        // The list comes back without it; asked for on its own, the panel has it.
        FakeProvider::$whileListing = function (): void {
            $this->onThePanel('ali_1', usedGb: 4);
        };

        $this->sync();

        $subscription->refresh();
        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertSame(4 * Traffic::GIGABYTE, $subscription->download_bytes, 'the numbers its panel gave when asked');
    }

    public function testAPanelOutOfReachIsRecordedAndLeftAloneForAWhile(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 12);
        FakeProvider::$down = [$this->server->id];

        $this->sync();
        self::assertSame(0, (int) $subscription->refresh()->download_bytes);
        self::assertSame(SubscriptionStatus::Active, $subscription->status);
        self::assertSame(ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $this->server->refresh()->last_error, 'on the server\'s page and the dashboard, like a check');

        // Back already, but the next runs within a while do not knock: each try costs the connect timeout.
        FakeProvider::$down = [];
        $this->sync();
        self::assertSame(0, (int) $subscription->refresh()->download_bytes);

        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->sync();
        self::assertSame(12 * Traffic::GIGABYTE, $subscription->refresh()->download_bytes);
        self::assertNull($this->server->refresh()->last_error, 'it answered');
        self::assertSame(now()->format('Y-m-d H:i'), $this->server->last_checked_at?->format('Y-m-d H:i'));
    }

    public function testAListReadBeforeAChangeElsewherePutsNoOlderNumbersBack(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 12);
        // While the list is on its way, a grant gives the service three days and reads the panel afresh.
        FakeProvider::$whileListing = function () use ($subscription): void {
            FakeProvider::$whileListing = null;
            Carbon::setTestNow(now()->addSecond());
            $this->onThePanel('ali_1', usedGb: 13);
            $this->service(ProvisioningService::class)->grant(Subscription::query()->findOrFail($subscription->id), 3, 0);
        };

        $this->sync();

        $subscription->refresh();
        self::assertSame(13 * Traffic::GIGABYTE, $subscription->download_bytes, 'the fresher read stands');
        self::assertSame('2026-10-23 12:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'), 'and so do the days given');
    }

    public function testALateAnswerAboutTheOldServerOfAServiceThatMovedMarksNothingDeleted(): void
    {
        $paris = $this->fakeServer('پاریس');
        $this->inbound($paris, '7');
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 12);
        $stale = Subscription::query()->findOrFail($subscription->id); // read by a sync, before…

        $this->service(ProvisioningService::class)->move($subscription, $paris, false); // …the admin moved it

        self::assertNull($this->service(ProvisioningService::class)->inspect($stale), 'its old panel has it no more');
        $subscription->refresh();
        self::assertSame([SubscriptionStatus::Active, $paris->id], [$subscription->status, $subscription->server_id], 'the move stands');
    }

    public function testAListThatRacedAMovePutsNoneOfItsNumbersOnTheMovedService(): void
    {
        $paris = $this->fakeServer('پاریس');
        $this->inbound($paris, '7');
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 12);
        // While the list is on its way, the admin moves the service to Paris: a new client there, counted from zero.
        FakeProvider::$whileListing = function () use ($subscription, $paris): void {
            FakeProvider::$whileListing = null;
            $this->service(ProvisioningService::class)->move(Subscription::query()->findOrFail($subscription->id), $paris, false);
        };

        $this->sync();

        $subscription->refresh();
        self::assertSame([$paris->id, SubscriptionStatus::Active, 0], [$subscription->server_id, $subscription->status, $subscription->usedBytes()], 'the old server\'s list is not put back on it');
    }

    public function testARunWithNoTimeLeftReadsNoPanel(): void
    {
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 12);

        $this->withTheTurnOver($this->sync(...));
        self::assertSame(0, $subscription->refresh()->usedBytes(), 'it stopped before the first server');
        self::assertNull($this->server->refresh()->last_checked_at);

        $this->sync();
        self::assertSame(12 * Traffic::GIGABYTE, $subscription->refresh()->usedBytes(), 'the next run reads it');
    }

    public function testAPanelThatCannotListIsAskedForEachService(): void
    {
        FakeProvider::$lists = false;
        $subscription = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $this->onThePanel('ali_1', usedGb: 12);

        $this->sync();

        self::assertSame(12 * Traffic::GIGABYTE, $subscription->refresh()->download_bytes);
    }

    public function testAServiceItsPanelFailsToAnswerAboutHoldsUpNoOther(): void
    {
        $logs = $this->logs();
        FakeProvider::$lists = false;
        $first = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1');
        $second = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2');
        $this->onThePanel('ali_1', usedGb: 12);
        $this->onThePanel('ali_2', usedGb: 7);
        // The panel answers about every client but the first.
        $asked = 0;
        FakeProvider::$onCall = static function (int $server, string $call) use (&$asked): void {
            if ($call === 'findClient' && $asked++ === 0) {
                throw new PanelApiException('The fake panel could not read ali_1.', 200, 'a broken row');
            }
        };

        $this->sync();

        self::assertSame([0, 7 * Traffic::GIGABYTE], [(int) $first->refresh()->download_bytes, $second->refresh()->download_bytes], 'the second read all the same');
        self::assertTrue($logs->hasWarningThatContains('Could not read subscription ' . $first->id . ' (ali_1)'));
    }

    public function testADeviceLimitChangedOnThePanelIsTheServicesFromThenOn(): void
    {
        $changed = $this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['ip_limit' => 1]);
        $unsaid = $this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['ip_limit' => 2]);
        $this->onThePanel('ali_1', usedGb: 1);
        $client = FakeProvider::$clients[$this->server->id]['ali_1'];
        // Raised to three by hand on the panel.
        FakeProvider::put($this->server, new ClientInfo(name: 'ali_1', enabled: true, downloadBytes: $client->downloadBytes, totalBytes: $client->totalBytes, expiry: $client->expiry, subscriptionUrl: $client->subscriptionUrl, ipLimit: 3));
        // A panel that keeps no IP limit (PasarGuard) says nothing of it.
        $this->onThePanel('ali_2', usedGb: 1);

        $this->sync();

        self::assertSame([3, 2], [$changed->refresh()->ip_limit, $unsaid->refresh()->ip_limit], "the panel's limit, or the shop's where the panel keeps none");
        $this->service(ProvisioningService::class)->grant($changed, 5, 0);
        self::assertSame(3, FakeProvider::lastUpdate('ali_1')->ipLimit, 'the next change of it keeps the panel\'s limit, never the old one');
    }

    private function onThePanel(string $name, int $usedGb, ?Expiry $expiry = null): void
    {
        FakeProvider::put($this->server, new ClientInfo(
            name: $name,
            enabled: true,
            downloadBytes: $usedGb * Traffic::GIGABYTE,
            totalBytes: 30 * Traffic::GIGABYTE,
            expiry: $expiry ?? Expiry::at(new \DateTimeImmutable('2026-10-20 12:00:00')),
            subscriptionUrl: "https://fake.test/sub/{$name}",
        ));
    }

    private function sync(): void
    {
        $this->service(SyncSubscriptionsTask::class)->run();
    }
}
