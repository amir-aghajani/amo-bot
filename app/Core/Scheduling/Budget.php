<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * How long the current piece of scheduled work may go on. A task working through a queue (broadcasts, report groups,
 * grants) stops at deadline(); the scheduler gives each turn its share of RUN_SECONDS, so a run does not grow with the
 * number of shops. Outside a run (a test, a request) work gets OUTSIDE_A_RUN.
 */
final class Budget
{
    /** Seconds a piece of work started outside a run may take. */
    public const OUTSIDE_A_RUN = 25;

    /** The end of the turn running now (microtime); null outside a run. */
    private ?float $deadline = null;

    /** The moment the work running now stops (microtime). */
    public function deadline(): float
    {
        return $this->deadline ?? microtime(true) + self::OUTSIDE_A_RUN;
    }

    /** Seconds left, with the fraction: a turn's share of a busy run may be under a second, and is still time to work. */
    public function seconds(): float
    {
        return max(0.0, $this->deadline() - microtime(true));
    }

    /** Start a turn of `$seconds`, or end the run (null). */
    public function turn(?float $seconds): void
    {
        $this->deadline = $seconds === null ? null : microtime(true) + max(0.0, $seconds);
    }
}
