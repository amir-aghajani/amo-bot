<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\Grants;

/**
 * Carries on with the grants under way («افزودن زمان و حجم», «هدیه همگانی») when no screen is doing it: every minute,
 * each server's part in turn, for the scheduler's share of time (shared hosting kills long cron runs). One that waits
 * for its panel tries it again here, once the panel is no longer left alone.
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
