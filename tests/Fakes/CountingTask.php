<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Scheduling\Task;

/** A scheduled task that only counts how often the scheduler ran it. */
final class CountingTask implements Task
{
    public int $runs = 0;

    public function run(): void
    {
        $this->runs++;
    }
}
