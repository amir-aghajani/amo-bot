<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\AutoRenewal;
use Psr\Log\LoggerInterface;

/**
 * «تمدید خودکار» on the clock (every 30 minutes, each shop): renews the services that came within the admin's days of
 * their deadline (AutoRenewal). Each renewal asks its panel, so the run stops at its share of the scheduler's time and
 * the rest wait for the next. One renewal that breaks is logged and stops no other.
 */
final class AutoRenewTask implements Task
{
    public function __construct(
        private readonly AutoRenewal $renewals,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $until = $this->budget->deadline();
        foreach ($this->renewals->due() as $subscription) {
            if (microtime(true) >= $until) {
                return;
            }

            try {
                $this->renewals->renew($subscription);
            } catch (\Throwable $e) {
                $this->logger->error('The automatic renewal of subscription {id} broke: {message}', ['id' => $subscription->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
