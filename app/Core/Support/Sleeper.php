<?php

declare(strict_types=1);

namespace App\Core\Support;

/** Waiting as a dependency (Telegram's flood waits, the pace of bulk sends), so the tests can swap in one that does not wait. */
interface Sleeper
{
    public function sleep(int $seconds): void;

    public function usleep(int $microseconds): void;
}
