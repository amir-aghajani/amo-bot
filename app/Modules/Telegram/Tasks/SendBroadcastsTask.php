<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Models\Broadcast;
use Psr\Log\LoggerInterface;

/**
 * Carries on with the broadcasts under way — messages and «لغو پین» runs, not paused ones: every minute, up to a batch
 * within the run's time (shared hosting kills long cron runs), then tells the admins whose messages went to everyone.
 * Runs are independent: one that fails is logged and the next goes on.
 */
final class SendBroadcastsTask implements Task
{
    /** Recipients per tick. */
    public const BATCH = 600;

    public function __construct(
        private readonly BroadcastService $broadcasts,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $deadline = $this->budget->deadline();

        // Each run's admin — where its message and its progress are — read with the runs (an unpin run's is its source's).
        foreach (Broadcast::sending()->with(['user', 'source.user'])->get() as $broadcast) {
            try {
                if ($this->broadcasts->process($broadcast, self::BATCH, $deadline)) {
                    $this->broadcasts->report($broadcast);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Broadcast {id} failed: {message}', ['id' => $broadcast->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
            if (microtime(true) >= $deadline) {
                return;
            }
        }
    }
}
