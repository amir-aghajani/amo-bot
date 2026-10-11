<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Telegram\Reports\ReportSender;

/**
 * Every minute (last in the run): sends what waits for the report group — what the panel and the other tasks queued, or
 * what a pause held back — then forgets old rows. The bot also sends after every update.
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
