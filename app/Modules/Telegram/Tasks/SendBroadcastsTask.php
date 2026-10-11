<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Models\Broadcast;
use Psr\Log\LoggerInterface;

/**
 * Every minute: carries on with the broadcasts being sent, a batch each within the scheduler's time budget, and tells
 * the admin when one is done. One run failing is logged and the next goes on.
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

        // Each run's admin (an unpin run's: its source's) is read with the runs: their chat holds the message and progress.
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
