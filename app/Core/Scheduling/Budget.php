<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * How long the scheduled work running now may go on. A task that works through a queue for as long as it may — a
 * broadcast's batches, the report groups, the grants — stops at deadline(); the scheduler hands each turn its share of
 * one run's time (Scheduler::RUN_SECONDS), so a run does not grow with the number of shops. Outside a run — a test, a
 * screen's request — the work gets a turn's time of its own (OUTSIDE_A_RUN).
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

    /**
     * The seconds the work running now has left — its fraction too: a turn's share of a busy run may be under a second,
     * and that is still time to work in.
     */
    public function seconds(): float
    {
        return max(0.0, $this->deadline() - microtime(true));
    }

    /** The scheduler starts a turn of `$seconds`, or ends the run's last (null). */
    public function turn(?float $seconds): void
    {
        $this->deadline = $seconds === null ? null : microtime(true) + max(0.0, $seconds);
    }
}
