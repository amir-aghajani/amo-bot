<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\Grants;

/**
 * Carries on with the grants under way when no screen is working them: every minute, each running part in turn, for the
 * scheduler's share of time (shared hosting kills long cron runs). A part waiting for its panel is tried again here once
 * that panel's backoff is over.
 */
final class GrantsTask implements Task
{
    public function __construct(
        private readonly Grants $grants,
        private readonly Budget $budget,
    ) {}

    public function run(): void
    {
        $this->grants->runAll($this->budget->deadline());
    }
}
