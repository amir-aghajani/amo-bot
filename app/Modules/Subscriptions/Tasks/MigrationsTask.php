<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\ServerMigrations;

/**
 * Carries on with the moves of servers' services («انتقال سرویس‌ها») when no page is working them: every minute, each
 * queued or running job in turn, for the scheduler's share of time (shared hosting kills long cron runs) — one waiting
 * for a panel is tried again once that panel's backoff is over —, then the clients moves left on panels that answer
 * again, removed.
 */
final class MigrationsTask implements Task
{
    public function __construct(
        private readonly ServerMigrations $migrations,
        private readonly Budget $budget,
    ) {}

    public function run(): void
    {
        $this->migrations->runAll($this->budget->deadline());
    }
}
