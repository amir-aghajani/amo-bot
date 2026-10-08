<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Telegram\Update\ReceivedUpdates;

/**
 * Every hour: the record of the updates every bot took is cut back to what Telegram could still send again — a
 * copy of an update older than ReceivedUpdates::KEEP_HOURS no longer comes.
 */
final class PruneUpdatesTask implements Task
{
    public function __construct(private readonly ReceivedUpdates $updates) {}

    public function run(): void
    {
        $this->updates->prune();
    }
}
