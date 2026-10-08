<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Subscriptions\Services\AutoRenewal;
use Psr\Log\LoggerInterface;

/**
 * «تمدید خودکار» on the clock: the services that came within the admin's days of their deadline are renewed from the
 * wallet, and each customer told what came of theirs (AutoRenewal). Each renewal asks its panel, so the run stops at its
 * share of the scheduler's time; the rest are renewed on the next run, as is a customer who tops up meanwhile. One
 * renewal that breaks on a fault of the shop's own is logged, and stops no other.
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
