<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/** A unit of scheduled work, listed in bootstrap/schedule.php and made by the container. */
interface Task
{
    public function run(): void;
}
