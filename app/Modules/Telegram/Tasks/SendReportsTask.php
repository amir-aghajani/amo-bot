<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Telegram\Reports\ReportSender;

/**
 * Every minute: what waits for the admins' report group goes out — what the panel queued (a receipt approved there),
 * what the other tasks just did (they run before this one), and what a flood limit or an unreachable Telegram held
 * back — as much as the minute takes, within the run's time; then old rows are forgotten. The bot also sends after
 * every update it answers, so this is what keeps a webhook installation's panel-made reports moving.
 */
final class SendReportsTask implements Task
{
    public function __construct(
        private readonly ReportSender $reports,
        private readonly Budget $budget,
    ) {}

    public function run(): void
    {
        $this->reports->flush($this->budget->seconds());
        $this->reports->prune();
    }
}
