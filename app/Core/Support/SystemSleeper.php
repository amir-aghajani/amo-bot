<?php

declare(strict_types=1);

namespace App\Core\Support;

/** The real thing: the process waits. */
final class SystemSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    public function usleep(int $microseconds): void
    {
        usleep($microseconds);
    }
}
