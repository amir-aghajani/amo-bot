<?php

declare(strict_types=1);

namespace App\Modules\Imports\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Imports\Imports;

/**
 * Daily: an import left open and untouched for a week cancelled, and whatever an ended import still keeps of the other
 * bot's data — its staged rows, its uploaded file, its panels' passwords among them — gone (Imports::prune()).
 */
final class PruneImportsTask implements Task
{
    public function __construct(private readonly Imports $imports) {}

    public function run(): void
    {
        $this->imports->prune();
    }
}
