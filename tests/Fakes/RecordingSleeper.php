<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Support\Sleeper;

/**
 * Waits nothing, like NullSleeper, and remembers every wait it was asked for — a flood wait sat out, the pause between
 * a broadcast's sends — so a test asserts the waiting rather than the clock.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<int> The seconds of each sleep(), in order */
    public array $seconds = [];

    /** @var list<int> The microseconds of each usleep(), in order */
    public array $microseconds = [];

    public function sleep(int $seconds): void
    {
        $this->seconds[] = $seconds;
    }

    public function usleep(int $microseconds): void
    {
        $this->microseconds[] = $microseconds;
    }
}
