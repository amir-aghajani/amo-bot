<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Renewals queued behind a paid period, with the shop not carrying what a period leaves unused: once that period has
 * ended (`period_ends_at`), what remains is capped at the renewed traffic and counted afresh
 * (ProvisioningService::startNextPeriod()). A panel out of reach — or one the shop leaves alone a while, or a service
 * another change holds — is tried again on a later run; until then the customer keeps what they had. A service deleted
 * since the run read it has nothing to start, and one that breaks on a fault of the shop's own is logged: neither stops
 * the others. Each start asks its panel, so the run stops at its share of the scheduler's time.
 */
final class NextPeriodTask implements Task
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $until = $this->budget->deadline();
        $due = Subscription::active()->where('period_ends_at', '<=', now())->with(['user', 'server'])->oldest('period_ends_at')->get();

        foreach ($due as $subscription) {
            if (microtime(true) >= $until) {
                return;
            }
            if ($subscription->server->isBackingOff()) {
                continue;
            }

            try {
                $this->provisioning->startNextPeriod($subscription);
            } catch (ProviderException | ServiceBusyException $e) {
                $this->logger->warning('The renewed period of subscription {id} waits: {message}', ['id' => $subscription->id, 'message' => $e->getMessage()]);
            } catch (ModelNotFoundException) {
                // Deleted since the run read it.
            } catch (\Throwable $e) {
                $this->logger->error('The renewed period of subscription {id} broke: {message}', ['id' => $subscription->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
