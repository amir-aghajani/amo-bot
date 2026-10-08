<?php

declare(strict_types=1);

namespace App\Modules\Updates\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Updates\Exceptions\UpdateRefusedException;
use App\Modules\Updates\Releases;

/**
 * Every day: AmoBot's newest release read from GitHub, so the owner's dashboard says when one is out — however seldom
 * the update screen is opened. GitHub out of reach is no failure of the shop's: what stood stands, and tomorrow's run
 * asks again.
 */
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
