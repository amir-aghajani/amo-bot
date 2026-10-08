<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * A unit of background work run by `schedule:run`. Implementations are resolved from the container.
 */
interface Task
{
    public function run(): void;
}
