<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Waiting, as a dependency: the Telegram client honours a flood wait and the broadcaster paces its
 * sends through this, so tests swap in one that does not wait at all.
 */
interface Sleeper
{
    public function sleep(int $seconds): void;

    public function usleep(int $microseconds): void;
}
