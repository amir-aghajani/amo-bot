<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Tasks;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Task;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\ServiceCleanup;
use App\Modules\Telegram\Reports\ShopReports;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Removes the services that are done with (every 15 minutes), in every shop that has some — an agent's bot that stopped
 * running too, since its services sit on the installation's panels — by that shop's rules (ServiceCleanup): its ended
 * services once their hours are over, while it removes them; its free tests that ended, or never connected, while it
 * cleans them up. Each is read from its panel again under its hold and removed only if its time is still up
 * (ProvisioningService::deleteIf()) — a service renewed or extended, or a test first connected, since the shop last read
 * it stays —, without a word to its customer; a test's `test_accounts` row stays, so the quota still counts it. The ended
 * services (not the tests) a shop's run removed go to its report group in one report.
 *
 * Test claims a process left when it died before its test was made are given back. A panel out of reach or in backoff,
 * or a service another change holds, is tried on a later run; one service that breaks is logged and stops no other.
 */
final class CleanupServicesTask implements Task
{
    /** A test's claim this old whose test never came was left by a process that died. */
    private const STALE_CLAIM_MINUTES = 30;

    public function __construct(
        private readonly ServiceCleanup $cleanup,
        private readonly ProvisioningService $provisioning,
        private readonly ShopReports $reports,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $now = now();
        $until = $this->budget->deadline();
        // The shops with a service over, a test service or a test claim: each probe stops at the shop's first such row.
        $shops = Bot::query()
            ->whereExists(Subscription::over(Subscription::acrossShops(), $now)->whereColumn('subscriptions.bot_id', 'bots.id'))
            ->orWhereExists(Subscription::acrossShops()->where('is_test', true)->whereColumn('subscriptions.bot_id', 'bots.id'))
            ->orWhereExists(TestAccount::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereNull('issued_at')->whereColumn('test_accounts.bot_id', 'bots.id'))
            ->orderBy('id')
            ->get();
        foreach ($shops as $shop) {
            if (microtime(true) >= $until) {
                return;
            }
            CurrentBot::run($shop, fn() => $this->cleanUp($now, $until));
        }
    }

    /**
     * The current shop's turn: its dead test claims given back, then its services whose time to go came — the ended ones
     * it removed told its report group in one report (ShopReports::servicesCleanedUp()).
     *
     * The report is queued after the loop, each removal committed in its own transaction before it
     * (ProvisioningService::deleteIf()): a run that dies midway reports nothing of what it removed, and a database error
     * queueing the report fails the task with the removals made. Accepted: the report is news for the admins, never the
     * record — and one report per removal, queued in its transaction, would flood the topic this one sums up.
     */
    private function cleanUp(Carbon $now, float $until): void
    {
        TestAccount::query()->whereNull('issued_at')->where('created_at', '<', $now->copy()->subMinutes(self::STALE_CLAIM_MINUTES))->delete();

        $removed = [];
        foreach ($this->cleanup->due($now) as $service) {
            if (microtime(true) >= $until) {
                break;
            }
            // One its panel no longer has is only the shop's to remove; any other needs its panel.
            if ($service->status !== SubscriptionStatus::Deleted && $service->server->isBackingOff()) {
                continue;
            }

            try {
                if ($this->provisioning->deleteIf($service, fn(Subscription $read): bool => $this->cleanup->stillDue($read, $now)) && !$service->is_test) {
                    $removed[] = $service;
                }
            } catch (ProviderException | ServiceBusyException $e) {
                $this->logger->warning('Service {id} waits to be removed: {message}', ['id' => $service->id, 'message' => $e->getMessage()]);
            } catch (ModelNotFoundException) {
                // Deleted since the run read it.
            } catch (\Throwable $e) {
                $this->logger->error('Removing service {id} broke: {message}', ['id' => $service->id, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
        $this->reports->servicesCleanedUp($removed);
    }
}
