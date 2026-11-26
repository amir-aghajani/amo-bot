<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * How often each scheduled task runs, and from what time of the day its runs are counted, as the owner set them
 * («زمان‌بندی کارها»): bootstrap/schedule.php gives each its defaults, this its own. Implemented by the Settings module
 * (ScheduleSettings), so Core names no module.
 */
interface Intervals
{
    /** Minutes between two runs of the task `$key` (Scheduler::key()): the owner's, or `$default` while they set none. */
    public function minutes(string $key, int $default): int;

    /**
     * The minute of the shop's day (0 for 00:00 to 1439 for 23:59) the task `$key`'s runs are counted from (Scheduler::
     * slot()): the owner's, or `$default` while they set none.
     */
    public function start(string $key, int $default): int;
}
