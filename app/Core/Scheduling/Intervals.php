<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * How often each scheduled task runs, as the owner set it («زمان‌بندی کارها»): bootstrap/schedule.php gives each its
 * default, this its own. Implemented by the Settings module (ScheduleSettings), so Core names no module.
 */
interface Intervals
{
    /** Minutes between two runs of the task `$key` (Scheduler::key()): the owner's, or `$default` while they set none. */
    public function minutes(string $key, int $default): int;
}
