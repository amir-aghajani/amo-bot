<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use Psr\Log\LoggerInterface;

/**
 * Keeps the shop's copy of every running service — every bot's — close to its panel: each run reads, per server with
 * active services, its whole client list at once (ProvisioningService::syncServer()) — the counters the reminders and the
 * screens show, a deadline the panel started at the first connection, a service that ended. Every read is recorded on
 * the server; a panel that failed is left alone a while (Server::isBackingOff()) — every attempt costs its connect
 * timeout, and under bot:poll the bot waits that long — and the run stops at its share of the scheduler's time.
 */
final class SyncSubscriptionsTask implements Task
{
    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $until = $this->budget->deadline();
        foreach (Server::query()->whereIn('id', Subscription::active(Subscription::acrossShops())->select('server_id'))->oldest('id')->get() as $server) {
            if (microtime(true) >= $until) {
                return;
            }
            if ($server->isBackingOff()) {
                continue;
            }

            try {
                $this->provisioning->syncServer($server);
            } catch (ProviderException $e) {
                $this->logger->warning('Could not sync the services on {server}: {message}', ['server' => $server->name, 'message' => $e->getMessage()]);
            }
        }
    }
}
