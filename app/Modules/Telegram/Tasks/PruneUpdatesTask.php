<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Task;
use App\Modules\Telegram\Update\ReceivedUpdates;

/** Hourly: forget the served updates Telegram can no longer send again (ReceivedUpdates::KEEP_HOURS). */
final class PruneUpdatesTask implements Task
{
    public function __construct(private readonly ReceivedUpdates $updates) {}

    public function run(): void
    {
        $this->updates->prune();
    }
}
