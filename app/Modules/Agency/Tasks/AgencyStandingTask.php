<?php

declare(strict_types=1);

namespace App\Modules\Agency\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Agency\Services\AgencyStanding;
use App\Modules\Bots\Models\Bot;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The agents' terms of selling, every hour (AgencyStanding::judge()): an agency's end warned of ahead and written down
 * once passed, a Jalali month below the minimum warned of near its end and judged once it is over, and a shop suspended
 * for it let sell again once this month reaches it. Every agent's shop is gone through — one whose bot does not run (no
 * token, a problem) too, since its website still sells —, not shop by shop: the agents are the main shop's customers. One
 * that breaks is logged and stops no other.
 */
final class AgencyStandingTask implements Task
{
    public function __construct(
        private readonly AgencyStanding $standing,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $now = Carbon::now();
        $until = $this->budget->deadline();
        foreach (Bot::activeAgents()->with('agent')->get() as $bot) {
            if (microtime(true) >= $until) {
                return;
            }

            try {
                $this->standing->judge($bot, $now);
            } catch (\Throwable $e) {
                $this->logger->error('The terms of selling of bot {bot} were not judged: {message}', ['bot' => $bot->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
