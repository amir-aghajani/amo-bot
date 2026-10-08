<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database\Lease;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Enums\ConnectionFailure;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\Enums\GrantStatus;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Grant;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Tasks\GrantsTask;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\Traffic;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;

/**
 * «افزودن زمان و حجم» on a server's page: days and traffic for the running services there — and, when the
 * admin ticks it, those still waiting for their first connection; ended ones never — with the admin's reason
 * in the message each customer gets. It is worked through a service at a time by whoever holds the part — the
 * screen, or the scheduler once the screen went quiet —, never gives a service twice, waits at the service it was on
 * while the panel is out of reach or another change holds that service, and runs one at a time on a server.
 */
final class ServerGrantsTest extends HttpTestCase
{
    private User $ali;
    private User $sara;
    private Server $server;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 12:00:00');
        $this->fakePanel();
        $this->telegram();
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali']);
        $this->sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara']);
        $this->server = $this->fakeServer();
        $this->plan = $this->plan();
    }

    public function testEveryRunningServiceGetsTheDaysAndTheTrafficAndItsCustomerIsTold(): void
    {
        $running = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10), 'download_bytes' => 20 * Traffic::GIGABYTE]));
        $waiting = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1', ['starts_at' => null, 'expires_at' => null]));
        self::assertSame(['running' => 1, 'unstarted' => 1], $this->decode($this->get("/api/admin/servers/{$this->server->id}/grants"))['audience']);

        $response = $this->start(['days' => '۳', 'traffic_gb' => '10', 'reason' => 'جبران قطعی سرور']);
        self::assertSame(201, $response->getStatusCode());
        $grant = $this->decode($response)['grant'];
        self::assertSame(
            ['running', 2, 0, 3, 10 * Traffic::GIGABYTE, true, false, self::ADMIN_USERNAME],
            [$grant['status'], $grant['total'], $grant['granted'], $grant['days'], $grant['traffic_bytes'], $grant['notify'], $grant['include_unstarted'], $grant['reviewer']],
        );
        self::assertSame([], FakeProvider::$updated, 'nothing is given until it is worked on');

        $grant = $this->work($grant['id']);
        self::assertSame(['done', 1, 1, 0], [$grant['status'], $grant['granted'], $grant['skipped'], $grant['failed']], 'the one waiting for its first connection is passed by');
        self::assertNotNull($grant['finished_at']);

        $running->refresh();
        self::assertSame('2026-10-03 12:00:00', $running->expires_at?->format('Y-m-d H:i:s'), 'three days after its deadline');
        self::assertSame(33, $running->duration_days);
        self::assertSame(40 * Traffic::GIGABYTE, $running->traffic_limit_bytes, 'ten on top of its thirty');
        self::assertSame(20 * Traffic::GIGABYTE, $running->remainingBytes());
        $spec = FakeProvider::lastUpdate('ali_1');
        self::assertSame(40 * Traffic::GIGABYTE, $spec->totalBytes);
        self::assertSame('2026-10-03 12:00:00', $spec->expiry->deadline()?->format('Y-m-d H:i:s'));
        self::assertSame(['ali_1'], FakeProvider::updatedNames());
        self::assertSame(30, $waiting->refresh()->duration_days);

        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(3, 10 * Traffic::GIGABYTE),
            'client' => 'ali_1',
            'note' => self::text(BotText::AdminNote, ['comment' => 'جبران قطعی سرور']),
            'expires' => Messages::expiry($running->expires_at, $running->duration_days),
            'remaining' => Messages::bytes(20 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(1001), 'what it got, why, and where it stands now');
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'only the one it reached is told');

        $history = $this->decode($this->get("/api/admin/servers/{$this->server->id}/grants"))['grants'];
        self::assertSame([$grant['id']], array_column($history, 'id'));
        self::assertSame('جبران قطعی سرور', $history[0]['reason']);
    }

    /**
     * A server deleted takes its grants' parts with it — the server's delete does, since no foreign key may cascade there
     * on MySQL (running_server_id is computed from the server) —, and a grant on other servers keeps its own.
     */
    public function testADeletedServerTakesItsGrantsPartsWithIt(): void
    {
        $service = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['expires_at' => now()->addDays(10)]));
        $grant = $this->decode($this->start(['days' => '3']))['grant'];
        $this->work($grant['id']);
        $service->delete();

        self::assertSame(204, $this->deleteJson("/api/admin/servers/{$this->server->id}")->getStatusCode());

        self::assertFalse(Server::query()->whereKey($this->server->id)->exists());
        self::assertSame(0, GrantPart::query()->where('server_id', $this->server->id)->count(), 'its parts went with it');
        self::assertTrue(Grant::query()->exists(), 'the grant is kept, as a gift that was made');
    }

    public function testServicesWaitingForTheirFirstConnectionGetItWhenTicked(): void
    {
        $waiting = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1', ['starts_at' => null, 'expires_at' => null]));

        $grant = $this->work($this->decode($this->start(['days' => 3, 'traffic_gb' => 10, 'include_unstarted' => true]))['grant']['id']);

        self::assertTrue($grant['include_unstarted']);
        self::assertSame(1, $grant['granted']);
        $waiting->refresh();
        self::assertNull($waiting->expires_at, 'the clock still starts at the first connection');
        self::assertSame(33, $waiting->duration_days, 'a longer term');
        self::assertSame(33 * 86400, FakeProvider::lastUpdate('sara_1')->expiry->pendingSeconds());
        self::assertSame(40 * Traffic::GIGABYTE, $waiting->traffic_limit_bytes);
        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(3, 10 * Traffic::GIGABYTE),
            'client' => 'sara_1',
            'note' => '',
            'expires' => Messages::expiry(null, 33),
            'remaining' => Messages::bytes(40 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(1002), 'the longer term, still waiting for the first connection');
    }

    public function testAServiceWhoseCustomerConnectedSinceTheShopLastAskedIsRunning(): void
    {
        // The shop still has it waiting for its first connection; the panel has started the clock.
        $started = $this->subscription($this->sara, $this->plan, $this->server, 'sara_1', ['starts_at' => null, 'expires_at' => null]);
        FakeProvider::put($this->server, new ClientInfo(
            name: 'sara_1',
            enabled: true,
            downloadBytes: Traffic::GIGABYTE,
            totalBytes: 30 * Traffic::GIGABYTE,
            expiry: Expiry::at(new \DateTimeImmutable('2026-10-19 12:00:00')),
            subscriptionUrl: $started->subscription_url,
        ));

        $grant = $this->work($this->decode($this->start(['days' => 3]))['grant']['id']);

        self::assertSame(1, $grant['granted'], 'the panel\'s word: it is running');
        self::assertSame('2026-10-22 12:00:00', $started->refresh()->expires_at?->format('Y-m-d H:i:s'));
    }

    public function testEndedServicesAreLeftAlone(): void
    {
        $running = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $ended = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2', ['status' => SubscriptionStatus::Expired, 'expires_at' => now()->subDay()]), enabled: false);
        // Both still running as far as the shop knows; the panel has one past its deadline, the other out of traffic.
        $lapsed = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1', ['expires_at' => now()->subHour()]));
        $usedUp = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_2', ['download_bytes' => 30 * Traffic::GIGABYTE]));

        $grant = $this->work($this->decode($this->start(['days' => 3, 'traffic_gb' => 5, 'include_unstarted' => true]))['grant']['id']);

        self::assertSame([3, 1, 2], [$grant['total'], $grant['granted'], $grant['skipped']], 'an ended one is not even counted; the two the panel has ended are passed by');
        self::assertSame(['ali_1'], FakeProvider::updatedNames());
        self::assertSame(33, $running->refresh()->duration_days);
        self::assertSame('2026-09-19 12:00:00', $ended->refresh()->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(SubscriptionStatus::Expired, $lapsed->refresh()->status);
        self::assertSame(SubscriptionStatus::Expired, $usedUp->refresh()->status);
        self::assertSame(30 * Traffic::GIGABYTE, $usedUp->traffic_limit_bytes);
    }

    public function testServicesSwitchedOffOrBoughtAfterwardsAreLeftAlone(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['status' => SubscriptionStatus::Disabled, 'disabled_at' => now()]), enabled: false);
        $offOnThePanel = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_2'), enabled: false);
        $running = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1'));

        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        $later = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_2'));
        $grant = $this->work($id);

        self::assertSame([2, 1, 1], [$grant['total'], $grant['granted'], $grant['skipped']], 'the one switched off on the panel itself is passed by');
        self::assertSame(['sara_1'], FakeProvider::updatedNames());
        self::assertSame(30, $offOnThePanel->refresh()->duration_days);
        self::assertSame(33, $running->refresh()->duration_days);
        self::assertSame(30, $later->refresh()->duration_days, 'bought after the grant was issued');
    }

    public function testAServiceTakesWhatItCanOfTheGrant(): void
    {
        $noTerm = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', ['duration_days' => 0, 'starts_at' => null, 'expires_at' => null]));
        $unlimited = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1', ['traffic_limit_bytes' => 0]));
        $neither = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_2', ['duration_days' => 0, 'starts_at' => null, 'expires_at' => null, 'traffic_limit_bytes' => 0]));

        $grant = $this->work($this->decode($this->start(['days' => 3, 'traffic_gb' => 5]))['grant']['id']);

        self::assertSame([2, 1], [$grant['granted'], $grant['skipped']], 'nothing for one that never ends and has no quota');
        $noTerm->refresh();
        self::assertNull($noTerm->expires_at);
        self::assertSame(0, $noTerm->duration_days, 'still never expires');
        self::assertSame([null, null], [FakeProvider::lastUpdate('ali_1')->expiry->deadline(), FakeProvider::lastUpdate('ali_1')->expiry->pendingSeconds()]);
        self::assertSame(35 * Traffic::GIGABYTE, $noTerm->traffic_limit_bytes);
        $unlimited->refresh();
        self::assertSame(0, $unlimited->traffic_limit_bytes, 'still unlimited');
        self::assertSame('2026-10-23 12:00:00', $unlimited->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame(['ali_1', 'sara_1'], FakeProvider::updatedNames(), 'sara_2 is not touched');
        self::assertSame(SubscriptionStatus::Active, $neither->refresh()->status);

        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(0, 5 * Traffic::GIGABYTE),
            'client' => 'ali_1',
            'note' => '',
            'expires' => Messages::expiry(null, 0),
            'remaining' => Messages::bytes(35 * Traffic::GIGABYTE),
        ])], $this->telegram()->sentTo(1001), 'only what it got');
        self::assertSame([self::text(BotText::ServiceGranted, [
            'gift' => Messages::gift(3, 0),
            'client' => 'sara_1',
            'note' => '',
            'expires' => Messages::expiry($unlimited->expires_at, $unlimited->duration_days),
            'remaining' => Messages::UNLIMITED,
        ])], $this->telegram()->sentTo(1002));
    }

    public function testARenewalQueuedBehindThePeriodMovesWithTheDeadlineAndKeepsTheTrafficGiven(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1', [
            'expires_at' => now()->addDays(35),
            'traffic_limit_bytes' => 60 * Traffic::GIGABYTE,
            'download_bytes' => 20 * Traffic::GIGABYTE,
            'period_ends_at' => now()->addDays(5),
            'next_period_bytes' => 30 * Traffic::GIGABYTE,
        ]));

        $this->work($this->decode($this->start(['days' => 2, 'traffic_gb' => 10]))['grant']['id']);

        $subscription->refresh();
        self::assertSame('2026-10-27 12:00:00', $subscription->expires_at?->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-27 12:00:00', $subscription->period_ends_at?->format('Y-m-d H:i:s'), 'the paid period gets the days too');
        self::assertSame(40 * Traffic::GIGABYTE, $subscription->next_period_bytes, 'the traffic given outlives the period');
        self::assertSame(70 * Traffic::GIGABYTE, $subscription->traffic_limit_bytes);
    }

    public function testAPanelOutOfReachHoldsTheGrantUntilItAnswers(): void
    {
        $first = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1'));
        FakeProvider::$down = [$this->server->id];

        $grant = $this->work($this->decode($this->start(['days' => 3]))['grant']['id']);

        self::assertSame(['running', 0], [$grant['status'], $grant['granted']]);
        self::assertSame(ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection timed out')), $grant['waiting_reason'], 'why, in the owner\'s words');
        self::assertSame(30, $first->refresh()->duration_days);
        self::assertSame([], $this->telegram()->calls());

        // Back, but left alone a while (Server::BACKOFF_MINUTES): every try at a panel that fails costs its timeout.
        FakeProvider::$down = [];
        $this->service(GrantsTask::class)->run();
        self::assertSame(30, $first->refresh()->duration_days);

        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(GrantsTask::class)->run();

        $part = GrantPart::query()->findOrFail($grant['id']);
        self::assertSame([GrantStatus::Done, 2, null], [$part->status, $part->granted, $part->waiting_reason], 'the scheduler carries on once the panel answers');
        self::assertSame(33, $first->refresh()->duration_days);
    }

    public function testAnUpdateThatNeverReachedThePanelIsTriedAgain(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        FakeProvider::$failing[$this->server->id]['updateClient'] = ConnectionFailure::Refused;

        $grant = $this->work($this->decode($this->start(['days' => 3]))['grant']['id']);
        self::assertSame(['running', 0, 0], [$grant['status'], $grant['granted'], $grant['failed']]);
        self::assertNotNull($grant['waiting_reason']);

        FakeProvider::$failing = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES)); // the panel failed: left alone a while first
        $grant = $this->work($grant['id']);

        self::assertSame(['done', 1], [$grant['status'], $grant['granted']]);
        self::assertCount(1, FakeProvider::$updated);
        self::assertSame(33, $subscription->refresh()->duration_days, 'given once');
    }

    public function testAnUpdateThatTimedOutIsNotTriedAgain(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        FakeProvider::$failing[$this->server->id]['updateClient'] = ConnectionFailure::Timeout;

        $grant = $this->work($this->decode($this->start(['days' => 3]))['grant']['id']);

        self::assertSame(['done', 0, 1], [$grant['status'], $grant['granted'], $grant['failed']], 'the panel may have taken it: never twice');
        self::assertSame('سرویس ali_1: ' . ProviderErrorPresenter::describe(new ConnectionException(ConnectionFailure::Timeout, 'Connection failed')), $grant['last_failure']);
        self::assertNull($grant['waiting_reason']);
        self::assertSame(30, $subscription->refresh()->duration_days);
    }

    public function testAServiceThePanelRefusesDoesNotStopTheRest(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1'));
        FakeProvider::$refusing[$this->server->id] = ['updateClient'];

        $grant = $this->work($this->decode($this->start(['days' => 3]))['grant']['id']);

        self::assertSame(['done', 0, 2], [$grant['status'], $grant['granted'], $grant['failed']]);
        self::assertSame('سرویس sara_1: ' . ProviderErrorPresenter::describe(new PanelApiException('The fake panel refused updateClient.', 200, 'refused by the test')), $grant['last_failure'], 'the latest one, and why');
        self::assertSame([], $this->telegram()->calls());
    }

    public function testOneGrantAtATimeAndItCanBeStopped(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];

        // A second press — the same moment or later — looks nothing up first: the database's one running part a server
        // decides, and nothing is left of the refused one.
        $second = $this->start(['traffic_gb' => 5]);
        self::assertSame(422, $second->getStatusCode());
        self::assertStringContainsString('«آلمان»', $this->decode($second)['errors']['grant'][0]);
        self::assertSame(1, Grant::query()->count());

        $cancelled = $this->decode($this->postJson("/api/admin/servers/{$this->server->id}/grants/{$id}/cancel"))['grant'];
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('cancelled', $this->work($id)['status']);
        self::assertSame(30, $subscription->refresh()->duration_days, 'nothing given');
        self::assertSame(422, $this->postJson("/api/admin/servers/{$this->server->id}/grants/{$id}/cancel")->getStatusCode(), 'not under way any more');
        self::assertSame(201, $this->start(['traffic_gb' => 5])->getStatusCode(), 'a new one may start');
    }

    public function testAServerTakesANewGrantOnceItsLastIsDone(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        self::assertSame('done', $this->work($this->decode($this->start(['days' => 3]))['grant']['id'])['status']);

        self::assertSame(201, $this->start(['days' => 2])->getStatusCode());
    }

    public function testStoppingAGrantMidWayKeepsWhatTheServicesReachedGot(): void
    {
        $reached = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $waiting = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1'));
        // The panel stops answering once the first service has had its share.
        FakeProvider::$onCall = function (int $server, string $call): void {
            if ($call === 'findClient' && FakeProvider::$updated !== []) {
                FakeProvider::$down = [$server];
            }
        };
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        $grant = $this->work($id);
        self::assertSame(['running', 1], [$grant['status'], $grant['granted']], 'it waits at the second service');

        $this->postJson("/api/admin/servers/{$this->server->id}/grants/{$id}/cancel");
        FakeProvider::$down = [];
        Carbon::setTestNow(now()->addMinutes(Server::BACKOFF_MINUTES));
        $this->service(GrantsTask::class)->run();

        self::assertSame([33, 30], [$reached->refresh()->duration_days, $waiting->refresh()->duration_days], 'what was given stays, nothing more is');
        self::assertSame(['ali_1'], FakeProvider::updatedNames());
    }

    public function testAServiceAnotherChangeHoldsIsComeBackToOnTheNextTurn(): void
    {
        $held = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        $renewal = Lease::take($held, 60) ?? self::fail('the service could not be held'); // a renewal is working on it

        $grant = $this->work($id);
        self::assertSame(['running', 0, 0, 0], [$grant['status'], $grant['granted'], $grant['skipped'], $grant['failed']], 'the part waits at that service');
        self::assertSame([], FakeProvider::$updated);

        $renewal->release();
        $grant = $this->work($id);
        self::assertSame(['done', 1], [$grant['status'], $grant['granted']]);
        self::assertSame(33, $held->refresh()->duration_days);
    }

    public function testAServiceGoneFromItsPanelSinceItWasReadIsPassedBy(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        // Removed on the panel by hand between the shop's read of the client and its update.
        FakeProvider::$onCall = static function (int $server, string $call): void {
            if ($call === 'updateClient') {
                unset(FakeProvider::$clients[$server]['ali_1']);
            }
        };

        $grant = $this->work($id);

        self::assertSame(['done', 0, 1, 0], [$grant['status'], $grant['granted'], $grant['skipped'], $grant['failed']]);
        self::assertSame([], $this->telegram()->calls(), 'nobody is told of a gift that never came');
    }

    public function testAHolderThatGoesQuietMidRunLosesThePartAndNoServiceIsGivenTwice(): void
    {
        $first = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $second = $this->mirrored($this->subscription($this->sara, $this->plan, $this->server, 'sara_1'));
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        // The screen's worker gives the first service, then stalls past its lease: the scheduler takes the part over and
        // works it to the end before the screen's worker moves on.
        $stalled = false;
        $byTheScheduler = [];
        $this->telegram()->on('sendMessage', function () use (&$stalled, &$byTheScheduler): array {
            if (!$stalled) {
                $stalled = true;
                Carbon::setTestNow(now()->addSeconds(ProvisioningService::holdSeconds($this->server) + 1));
                $this->service(GrantsTask::class)->run();
                $byTheScheduler = FakeProvider::updatedNames();
            }

            return ['message_id' => 1];
        });

        $grant = $this->work($id);

        self::assertSame(['ali_1', 'sara_1'], $byTheScheduler, 'the scheduler took the part over and gave the second service');
        self::assertSame(['done', 2], [$grant['status'], $grant['granted']]);
        self::assertSame(['ali_1', 'sara_1'], FakeProvider::updatedNames(), 'each given once: the stalled worker, back, finds the part no longer its own');
        self::assertSame([33, 33], [$first->refresh()->duration_days, $second->refresh()->duration_days]);
    }

    public function testTheSchedulerOutOfTimeLeavesTheGrantForItsNextRun(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        $task = $this->service(GrantsTask::class);

        $this->withTheTurnOver($task->run(...));
        self::assertSame(30, $subscription->refresh()->duration_days);

        $task->run();
        self::assertSame([GrantStatus::Done, 33], [GrantPart::query()->findOrFail($id)->status, $subscription->refresh()->duration_days]);
    }

    public function testAWorkerHoldingTheGrantKeepsOthersOut(): void
    {
        $subscription = $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));
        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        GrantPart::query()->whereKey($id)->update(['lease_token' => 'someone-else', 'leased_until' => now()->addMinute()]);

        self::assertSame(0, $this->work($id)['granted']);
        self::assertSame(30, $subscription->refresh()->duration_days);

        Carbon::setTestNow(now()->addMinutes(2));
        self::assertSame(1, $this->work($id)['granted'], 'a holder that went quiet loses it');
    }

    public function testTheCustomersAreToldOnlyWhenTheAdminSaysSo(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));

        $grant = $this->work($this->decode($this->start(['days' => 3, 'notify' => false]))['grant']['id']);

        self::assertSame(1, $grant['granted']);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testWhatTheAdminTypesIsChecked(): void
    {
        $this->mirrored($this->subscription($this->ali, $this->plan, $this->server, 'ali_1'));

        $errors = fn(array $body): array => $this->decode($this->start($body))['errors'] ?? [];
        self::assertSame(['grant' => ['زمان یا حجمی برای افزودن وارد کنید.']], $errors(['days' => '', 'traffic_gb' => '0']));
        self::assertArrayHasKey('days', $errors(['days' => 400]));
        self::assertArrayHasKey('days', $errors(['days' => 'سه']));
        self::assertArrayHasKey('traffic_gb', $errors(['traffic_gb' => '10001']));
        self::assertArrayHasKey('traffic_gb', $errors(['traffic_gb' => '1.234']));
        self::assertArrayHasKey('reason', $errors(['days' => 1, 'reason' => str_repeat('ا', Input::NOTE_MAX + 1)]));
        self::assertSame(0, Grant::query()->count());

        $empty = $this->fakeServer('هلند');
        $response = $this->postJson("/api/admin/servers/{$empty->id}/grants", ['days' => 3]);
        self::assertSame(['grant' => ['روی این سرور سرویس فعالی نیست که این زمان یا حجم به آن برسد.']], $this->decode($response)['errors']);
        self::assertSame(404, $this->postJson('/api/admin/servers/999/grants', ['days' => 3])->getStatusCode());

        $id = $this->decode($this->start(['days' => 3]))['grant']['id'];
        self::assertSame(404, $this->postJson("/api/admin/servers/{$empty->id}/grants/{$id}/run")->getStatusCode(), 'another server\'s grant');
    }

    /** The service, its client on the fake panel as the row has it (switched off there when `$enabled` is false). */
    private function mirrored(Subscription $subscription, bool $enabled = true): Subscription
    {
        FakeProvider::mirror($subscription, $enabled);

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function start(array $body): ResponseInterface
    {
        return $this->postJson("/api/admin/servers/{$this->server->id}/grants", $body);
    }

    /** @return array<string, mixed> The grant after a request's worth of work on it. */
    private function work(int $id): array
    {
        return $this->decode($this->postJson("/api/admin/servers/{$this->server->id}/grants/{$id}/run"))['grant'];
    }
}
