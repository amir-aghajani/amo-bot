<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Telegram\Reports\DailyReport;

/** Queues each shop's daily report of its day before once it is due (DailyReport::sendIfDue()); every 10 minutes. */
final class DailyReportTask implements Task
{
    public function __construct(private readonly DailyReport $daily) {}

    public function run(): void
    {
        $this->daily->sendIfDue();
    }
}
