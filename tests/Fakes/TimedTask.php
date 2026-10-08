<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;

/** A scheduled task that notes, each time it runs, the seconds it was given (Budget::seconds()). */
final class TimedTask implements Task
{
    /** @var list<float> */
    public array $given = [];

    public function __construct(private readonly Budget $budget) {}

    public function run(): void
    {
        $this->given[] = $this->budget->seconds();
    }
}
