<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Support\Sleeper;

/** Never waits: a flood wait or the broadcast pacing costs a test nothing. */
final class NullSleeper implements Sleeper
{
    public function sleep(int $seconds): void {}

    public function usleep(int $microseconds): void {}
}
