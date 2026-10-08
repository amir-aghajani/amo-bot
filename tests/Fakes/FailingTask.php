<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Scheduling\Task;

/** A scheduled task that always throws: what the scheduler does with a broken one. */
final class FailingTask implements Task
{
    public function run(): void
    {
        throw new \RuntimeException('the task broke');
    }
}
