<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Scheduler;
use App\Modules\Accounts\Tasks\PruneAccountsTask;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Orders\Tasks\ResumeDeliveriesTask;
use App\Modules\Payments\Tasks\AutoApproveReceiptsTask;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Subscriptions\Tasks\GrantsTask;
use App\Modules\Subscriptions\Tasks\NextPeriodTask;
use App\Modules\Subscriptions\Tasks\SendRemindersTask;
use App\Modules\Subscriptions\Tasks\SyncSubscriptionsTask;
use App\Modules\Support\Tasks\PruneTicketsTask;
use App\Modules\Telegram\Tasks\PruneUpdatesTask;
use App\Modules\Telegram\Tasks\SendBroadcastsTask;
use App\Modules\Telegram\Tasks\SendReportsTask;
use App\Modules\Updates\Tasks\CheckReleasesTask;
use Tests\DatabaseTestCase;

/**
 * The shop's own schedule (bootstrap/schedule.php) as the container builds it: every task the shop relies on, at the
 * interval CLAUDE.md gives it, in the order a run takes them — a shop's own work in every bot's shop, the servers' work
 * once across them, the reminders right after the sync they read, the report groups last — and the whole of it run
 * together on a shop with something in it, no task failing.
 */
final class ShopScheduleTest extends DatabaseTestCase
{
    public function testTheShopRunsEachTaskAtItsIntervalInTheOrderARunTakesThem(): void
    {
        $tasks = array_map(static fn(array $task): array => [$task['interval'] / 60, $task['eachBot'] ? 'each shop' : 'across shops'], $this->service(Scheduler::class)->tasks());

        self::assertSame([
            AutoApproveReceiptsTask::class => [1, 'each shop'],
            ResumeDeliveriesTask::class => [1, 'each shop'],
            ExpireOrdersTask::class => [60, 'each shop'],
            SendBroadcastsTask::class => [1, 'each shop'],
            AutoRenewTask::class => [30, 'each shop'],
            NextPeriodTask::class => [10, 'each shop'],
            GrantsTask::class => [1, 'across shops'],
            SyncSubscriptionsTask::class => [15, 'across shops'],
            SendRemindersTask::class => [15, 'each shop'],
            PruneUpdatesTask::class => [60, 'across shops'],
            PruneAccountsTask::class => [60, 'across shops'],
            PruneTicketsTask::class => [60, 'each shop'],
            CheckReleasesTask::class => [24 * 60, 'across shops'],
            SendReportsTask::class => [1, 'each shop'],
        ], $tasks);
    }

    public function testAForcedRunGoesThroughEveryTaskInEveryShopAndNoneFails(): void
    {
        $this->telegram();
        $server = $this->sellingServer();
        $this->buy($this->customer(['username' => 'ali']), $this->plan([], $server), $server);
        $this->agentBot();
        $scheduler = $this->service(Scheduler::class);
        $logs = $this->logs();

        $ran = $scheduler->run(force: true);

        self::assertSame(array_keys($scheduler->tasks()), $ran, 'every task, in order');
        self::assertFalse($logs->hasErrorRecords() || $logs->hasCriticalRecords(), 'no task failed: ' . implode(' | ', array_column($logs->getRecords(), 'message')));
        self::assertNotNull($server->refresh()->last_checked_at, "the sync read the server's panel");
        self::assertNotNull($scheduler->lastRunAt());
    }
}
