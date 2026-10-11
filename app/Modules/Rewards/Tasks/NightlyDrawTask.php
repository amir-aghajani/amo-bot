<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Rewards\Services\Lottery;

/** Runs each shop's nightly draw once it is due (Lottery::drawIfDue()); every 10 minutes. */
final class NightlyDrawTask implements Task
{
    public function __construct(private readonly Lottery $lottery) {}

    public function run(): void
    {
        $this->lottery->drawIfDue();
    }
}
