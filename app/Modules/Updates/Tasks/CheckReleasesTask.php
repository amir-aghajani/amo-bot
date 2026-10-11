<?php

declare(strict_types=1);

namespace App\Modules\Updates\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Updates\Exceptions\UpdateRefusedException;
use App\Modules\Updates\Releases;

/** Daily: reads the newest release so the dashboard says when one is out; GitHub out of reach is simply tried tomorrow. */
final class CheckReleasesTask implements Task
{
    public function __construct(private readonly Releases $releases) {}

    public function run(): void
    {
        try {
            $this->releases->check();
        } catch (UpdateRefusedException) {
            // Logged where GitHub did not answer (GitHub); the release read last stands.
        }
    }
}
