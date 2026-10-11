<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Lease;
use App\Core\Database\Transitions;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Sleeper;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Services\ServerHealth;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ExtraUnavailableException;
use App\Modules\Subscriptions\Exceptions\MoveException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Every change of a service on its panel — created for a purchase, renewed, given days and traffic, sold extras, started
 * on a queued period, re-linked, switched, moved, deleted — and every read back.
 *
 * The panel is the truth about a client: its answers reach the row through one mapping (values()), stamped with when
 * the panel was asked. Three rules keep concurrent work safe:
 * - A change holds the service with a lease on its row (holding()), renewed before every panel call for as long as the
 *   call may take (holdSeconds()), so two changes never interleave; the second waits, then works on what the first left.
 * - A read that is not a change only writes over the row as it read it (apply()), so a sync that read the panel before
 *   a renewal never puts older numbers back, nor marks deleted a service that moved meanwhile.
 * - A change the panel took is always recorded, even when its lease was lost meanwhile (record()): undoing or retrying
 *   it would apply it twice.
 * Every contact is recorded on the server (ServerHealth), so a panel that stops answering is left alone a while.
 */
final class ProvisioningService
{
    /**
     * The most HTTP requests one call to a panel makes: a 3x-ui update reads the client, writes it, reads it back with
     * its counters and link, and may sign in again on the way.
     */
    private const REQUESTS_A_CALL = 8;

    /** Seconds a hold keeps on top of the panel call it covers, for the shop's reads and writes around it. */
    private const HOLD_MARGIN = 30;

    /** How long a change waits for another change of the same service: tries, and the pause between them (5 s in all). */
    private const WAIT_TRIES = 20;
    private const WAIT_MICROSECONDS = 250_000;

    /** Why rotateLink() refuses: the service does not run, or its panel cannot rotate. */
    public const ROTATE_INACTIVE = 'لینک فقط برای سرویس فعال عوض می‌شود.';
    public const ROTATE_UNSUPPORTED = 'سرور این سرویس تغییر لینک را پشتیبانی نمی‌کند.';

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServerHealth $health,
        private readonly ServerSelector $selector,
        private readonly ClientNaming $naming,
        private readonly RenewalSettings $renewal,
        private readonly Sleeper $sleeper,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * How long a change holds a service at a time: the panel's timeout for each request one call may make, plus a margin
     * — the slowest of the servers involved (a move's two). A holder that died lets go this long after its last call.
     * Grants hold their part on a server as long: a service's turn there is one call between two steps.
     */
    public static function holdSeconds(Server $server, Server ...$others): int
    {
        $timeout = max(array_map(static fn(Server $panel): int => PanelConnection::of($panel)->timeout, [$server, ...$others]));

        return $timeout * self::REQUESTS_A_CALL + self::HOLD_MARGIN;
    }

    /**
     * Create the client for a paid purchase and its service row. `$complete` (the order's completion) runs in the
     * transaction that writes the row; if that transaction fails, the client is deleted from the panel again so a retry
     * leaves nothing behind.
     *
     * @param \Closure(Subscription): void $complete
     * @throws NoServerAvailableException when the plan cannot be delivered now, or the panel gave no link
     * @throws ProviderException
     */
    public function provision(Order $order, \Closure $complete): Subscription
    {
        $plan = $order->plan ?? throw new \LogicException("Order #{$order->id} has no plan to provision.");
        $user = $order->user;
        ['server' => $server, 'inbounds' => $inbounds] = $this->selector->resolve($plan, $order->server_id);

        // The customer gets one subscription link and nothing else: a panel that stopped serving links since its last
        // check gets no client, and the order fails with the reason.
        if (!$this->onPanel($server, static fn(ProviderInterface $panel): bool => $panel->servesSubscriptions())) {
            throw new NoServerAvailableException("سرور اشتراک (Subscription) در پنل «{$server->name}» فعال نیست؛ آن را فعال کنید و سرور را دوباره بررسی کنید.");
        }

        // One client on every inbound the plan sells there; its term starts at the first connection.
        $spec = $this->spec($user, $this->naming->next($user, $server), $plan->trafficBytes(), ServiceTerms::termOf($plan->duration_hours), $plan->ip_limit);
        $observed = now();
        $client = $this->onPanel($server, static fn(ProviderInterface $panel): ClientInfo => $panel->createClient($inbounds->pluck('remote_key')->values()->all(), $spec));
        if ($client->subscriptionUrl === null) {
            $this->takeBack($server, $spec->name);

            throw new NoServerAvailableException("پنل «{$server->name}» برای کلاینت لینک اشتراک نساخت؛ سرور اشتراک پنل را بررسی کنید.");
        }

        try {
            $subscription = $this->db->transaction(function () use ($order, $user, $plan, $server, $spec, $client, $observed, $complete): Subscription {
                $subscription = new Subscription([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'server_id' => $server->id,
                    'remote_name' => $client->name,
                    'status' => SubscriptionStatus::Active,
                    'ip_limit' => $spec->ipLimit,
                    'duration_hours' => $plan->duration_hours,
                    // «تمدید خودکار» starts as the admin set it for new services, on a plan that can renew itself at all.
                    'auto_renew' => $this->renewal->autoRenewByDefault() && $this->renewal->autoRenews($plan),
                ]);
                $subscription->fill(self::mirror($subscription, $client, $observed))->save();
                $order->forceFill(['subscription_id' => $subscription->id])->save();
                // Whoever words the delivery reads the service off this order.
                $order->setRelation('subscription', $subscription);
                $complete($subscription);

                return $subscription;
            });
        } catch (\Throwable $e) {
            $this->takeBack($server, $spec->name);

            throw $e;
        }

        $this->logger->info('Provisioned subscription {id} for user {user} on {server}', ['id' => $subscription->id, 'user' => $user->id, 'server' => $server->name]);

        return $subscription;
    }

    /**
     * Apply the renewal order's plan to its service (ServiceTerms::renewal()), from what the panel says right now — the
     * traffic left and the deadline are the panel's. `$complete` runs in the transaction that writes the row.
     *
     * @param \Closure(Subscription): void $complete
     * @throws NotFoundException when the panel no longer has the client: there is nothing to renew
     * @throws ProviderException|ServiceBusyException
     */
    public function renew(Order $order, \Closure $complete): Subscription
    {
        $subscription = $order->subscription ?? throw new \LogicException("Renewal order #{$order->id} has no subscription.");

        return $this->holding($subscription, function (Lease $lease) use ($order, $subscription, $complete): Subscription {
            $plan = $order->plan ?? $subscription->plan ?? throw new \LogicException("Renewal order #{$order->id} has no plan.");
            $this->read($subscription, $lease);

            $terms = ServiceTerms::renewal($subscription, $plan, $this->renewal->carriesTraffic());
            $this->applyTerms($subscription, $terms, $lease, ['plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'ip_limit' => $terms->ipLimit, 'disabled_at' => null] + $terms->columns(), $complete);

            return $subscription;
        });
    }

    /**
     * Start the period a renewal was queued behind, once `period_ends_at` has passed: what remains is capped at the
     * renewed traffic and counted afresh (ServiceTerms::nextPeriod()), by the panel's counters now. A client the panel
     * no longer has, or a service that used everything, has nothing to cap: the queued period is just dropped.
     *
     * @throws ProviderException|ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    public function startNextPeriod(Subscription $subscription): void
    {
        $this->holding($subscription, function (Lease $lease) use ($subscription): void {
            if ($subscription->period_ends_at === null || $subscription->next_period_bytes === null) {
                return; // started meanwhile
            }

            $client = $this->read($subscription, $lease, required: false);
            $terms = $client !== null ? ServiceTerms::nextPeriod($subscription) : null;
            $over = ['period_ends_at' => null, 'next_period_bytes' => null];
            if ($terms === null) {
                $lease->write($over);

                return;
            }

            $this->applyTerms($subscription, $terms, $lease, $over);
            $this->logger->info('Subscription {id} began its renewed period with {bytes} bytes', ['id' => $subscription->id, 'bytes' => $terms->totalBytes]);
        });
    }

    /**
     * Add days and traffic to a service — its share of a grant, or support's extension — worked out from the row as the
     * panel last reported it (ServiceTerms::grant()).
     *
     * @throws ProviderException|ServiceBusyException
     */
    public function grant(Subscription $subscription, int $days, int $bytes): void
    {
        $this->holding($subscription, function (Lease $lease) use ($subscription, $days, $bytes): void {
            $terms = ServiceTerms::grant($subscription, $days, $bytes);
            $this->applyTerms($subscription, $terms, $lease, $terms->columns());
        });
    }

    /**
     * Deliver the extra traffic or time an order bought onto its service (ServiceTerms::extra()), from what the panel
     * says right now. The service must still be able to take it by the panel's numbers (CustomerExtras::problem()):
     * switched off by support since, or ended on the other count, the order fails with the reason. `$complete` runs in
     * the transaction that writes the row.
     *
     * @param \Closure(Subscription): void $complete
     * @throws NotFoundException when the panel no longer has the client
     * @throws ExtraUnavailableException
     * @throws ProviderException|ServiceBusyException
     */
    public function addExtra(Order $order, \Closure $complete): Subscription
    {
        $subscription = $order->subscription ?? throw new \LogicException("Extra order #{$order->id} has no subscription.");
        $kind = ExtraKind::of($order->type) ?? throw new \LogicException("Order #{$order->id} buys no extra.");

        return $this->holding($subscription, function (Lease $lease) use ($order, $subscription, $kind, $complete): Subscription {
            $this->read($subscription, $lease);
            $problem = CustomerExtras::problem($subscription, $kind);
            if ($problem !== null) {
                throw new ExtraUnavailableException($problem);
            }

            $terms = ServiceTerms::extra($subscription, (int) $order->days, (int) $order->traffic_bytes);
            $this->applyTerms($subscription, $terms, $lease, $terms->columns(), $complete);
            $this->logger->info('Subscription {id} got {extra} from order {order}', ['id' => $subscription->id, 'extra' => $kind->words($kind->quantityOf($order)), 'order' => $order->id]);

            return $subscription;
        });
    }

    /**
     * Read the service from its panel now and bring the row up to date (apply()); null when the panel no longer has the
     * client, in which case the row is marked deleted. `$presence` also asks whether it is connected (a customer's screen).
     *
     * @throws ProviderException when the panel cannot be asked
     */
    public function inspect(Subscription $subscription, bool $presence = false): ?ClientInfo
    {
        $observed = now();
        $client = $this->onPanel($subscription->server, static fn(ProviderInterface $panel): ?ClientInfo => $panel->findClient($subscription->remote_name, $presence));
        $this->apply($subscription, $client, $observed);

        return $client;
    }

    /**
     * The service for its customer's own screen (the bot's, the website's): inspect() with presence. A panel in backoff
     * (Server::isBackingOff()) is not asked, so the customer does not wait out its timeout; a panel that fails is logged.
     * Either way the caller learns it could not read the service — the row's copy is never passed off as fresh.
     *
     * @throws ServiceNotReadException
     */
    public function look(Subscription $subscription): ?ClientInfo
    {
        if ($subscription->server->isBackingOff()) {
            throw new ServiceNotReadException();
        }

        try {
            return $this->inspect($subscription, presence: true);
        } catch (ProviderException $e) {
            $this->logger->warning('Could not inspect subscription {id} on {server}: {message}', ['id' => $subscription->id, 'server' => $subscription->server->name, 'message' => $e->getMessage()]);

            throw new ServiceNotReadException($e);
        }
    }

    /**
     * inspect() for every active service on the server (every bot's) from one list of the panel's clients. A client the
     * list does not show is asked for on its own — only that answer marks a service deleted, never a list that came back
     * short —; a panel that cannot list its clients is asked for each. Returns how many services it read.
     *
     * @throws ProviderException when the panel cannot be asked
     */
    public function syncServer(Server $server): int
    {
        $subscriptions = Subscription::active($server->subscriptions()->getQuery())->get();
        if ($subscriptions->isEmpty()) {
            return 0;
        }

        $observed = now();
        $clients = [];
        try {
            foreach ($this->onPanel($server, static fn(ProviderInterface $panel): array => $panel->listClients()) as $client) {
                $clients[$client->name] = $client;
            }
        } catch (UnsupportedOperationException) {
            // Asked for one by one below.
        }

        foreach ($subscriptions as $subscription) {
            $subscription->setRelation('server', $server);
            $client = $clients[$subscription->remote_name] ?? null;
            $client !== null ? $this->apply($subscription, $client, $observed) : $this->inspect($subscription);
        }
        $this->health->record($server, null);

        return $subscriptions->count();
    }

    /** Whether the service's panel can give its client a new link («تغییر لینک»): the bot shows the button by it. */
    public function rotates(Subscription $subscription): bool
    {
        return $this->providers->capabilities($subscription->server->driver)->linkRotation;
    }

    /** Whether rotateLink() would work now: the service runs and its panel can rotate. */
    public function rotatable(Subscription $subscription): bool
    {
        return $subscription->isActive() && $this->rotates($subscription);
    }

    /**
     * «تغییر لینک»: fresh credentials and a fresh subscription link for the client, so the old link — and every device
     * on it — stops working; limits, expiry and counters stay. The row learns the new link; the caller sends it.
     *
     * @throws ValidationException 422 on `status`: the service does not run, or its panel cannot rotate
     * @throws ProviderException|ServiceBusyException
     */
    public function rotateLink(Subscription $subscription): Subscription
    {
        if (!$this->rotatable($subscription)) {
            throw ValidationException::on('status', $subscription->isActive() ? self::ROTATE_UNSUPPORTED : self::ROTATE_INACTIVE);
        }

        return $this->holding($subscription, function (Lease $lease) use ($subscription): Subscription {
            $observed = now();
            $client = $this->held($lease, $subscription->server, static fn(ProviderInterface $panel): ClientInfo => $panel->rotateClientCredentials($subscription->remote_name));
            // The old link is dead either way; without a new one there is nothing honest to keep.
            if ($client->subscriptionUrl === null) {
                throw new ProviderException("The panel rotated the credentials of {$subscription->remote_name} but serves no subscription link for it.");
            }
            $this->record($subscription, $client, $observed, $lease);

            $this->logger->info('Rotated the link of subscription {id} on {server}', ['id' => $subscription->id, 'server' => $subscription->server->name]);

            return $subscription;
        });
    }

    /**
     * Switch the client off (or back on) on the panel, then the row: active → disabled (stamped), disabled → active.
     * False when the service is no longer in the state the switch is for (someone just did it).
     *
     * @throws ProviderException|ServiceBusyException
     */
    public function setEnabled(Subscription $subscription, bool $enabled): bool
    {
        return $this->holding($subscription, function (Lease $lease) use ($subscription, $enabled): bool {
            if ($subscription->status !== ($enabled ? SubscriptionStatus::Disabled : SubscriptionStatus::Active)) {
                return false;
            }

            $this->held($lease, $subscription->server, static fn(ProviderInterface $panel) => $panel->setClientEnabled($subscription->remote_name, $enabled));

            return $enabled
                ? Transitions::move($subscription, 'status', [SubscriptionStatus::Disabled], SubscriptionStatus::Active, ['disabled_at' => null])
                : Transitions::move($subscription, 'status', [SubscriptionStatus::Active], SubscriptionStatus::Disabled, ['disabled_at' => now()]);
        });
    }

    /**
     * Move the service to another server: a new client there with what is left (the remaining traffic as its quota, the
     * same deadline, or the same pending term), then the old client deleted and the row pointed at the new one. The old
     * panel is read first so what is left is current. With `$leavePrevious` (that panel is out of reach) it is not
     * contacted: the row's last numbers are carried over and the old client stays there for the admin to delete. A
     * delete the old panel refuses takes the new client back — nothing moved.
     *
     * @throws NoServerAvailableException when the target cannot take the service
     * @throws MoveException naming the step that failed
     * @throws ServiceBusyException
     */
    public function move(Subscription $subscription, Server $target, bool $leavePrevious): void
    {
        $this->holding($subscription, function (Lease $lease) use ($subscription, $target, $leavePrevious): void {
            $source = $subscription->server;
            if ($source->id === $target->id) {
                throw MoveException::service('این سرویس همین حالا روی این سرور است.');
            }
            $inbounds = $this->selector->moveTarget($subscription->plan, $target);

            // What is left, as the old panel says now.
            if (!$leavePrevious) {
                try {
                    $this->read($subscription, $lease, required: false);
                } catch (ProviderException $e) {
                    throw MoveException::previousServer($source, $e);
                }
            }
            if (!$subscription->isActive() || $subscription->isExpiredByTime() || $subscription->isExpiredByTraffic()) {
                throw MoveException::service("سرویس {$subscription->remote_name} دیگر فعال نیست؛ چیزی برای انتقال نمانده.");
            }

            // The new client on the target.
            $spec = $this->spec($subscription->user, $this->naming->nameOn($subscription, $target), $subscription->remainingBytes() ?? 0, ServiceTerms::expiryOf($subscription), $subscription->ip_limit);
            $observed = now();
            try {
                $client = $this->held($lease, $target, static fn(ProviderInterface $panel): ClientInfo => $panel->createClient($inbounds->pluck('remote_key')->values()->all(), $spec));
            } catch (ProviderException $e) {
                throw MoveException::targetServer($target, $e);
            }
            if ($client->subscriptionUrl === null) {
                $this->takeBack($target, $spec->name);

                throw MoveException::targetServer($target, 'برای کلاینت لینک اشتراک نساخت؛ سرور اشتراک پنلش را بررسی کنید.');
            }

            // The old client off its panel; if that fails, the new one goes again and nothing moved.
            if (!$leavePrevious) {
                try {
                    $this->held($lease, $source, static fn(ProviderInterface $panel) => $panel->deleteClient($subscription->remote_name));
                } catch (NotFoundException) {
                    // Gone from the old panel already.
                } catch (ProviderException|ServiceBusyException $e) {
                    $this->takeBack($target, $spec->name);

                    throw $e instanceof ProviderException ? MoveException::previousServer($source, $e) : $e;
                }
            }

            try {
                $this->record($subscription, $client, $observed, $lease, ['server_id' => $target->id, 'remote_name' => $client->name]);
            } catch (ModelNotFoundException $e) {
                // The service was deleted while it moved: its new client is nobody's.
                $this->takeBack($target, $spec->name);

                throw $e;
            }
            $subscription->setRelation('server', $target);

            $this->logger->info('Moved subscription {id} from {from} to {to}{left}', [
                'id' => $subscription->id,
                'from' => $source->name,
                'to' => $target->name,
                'left' => $leavePrevious ? ' (the old client was left on the previous server)' : '',
            ]);
        }, $target);
    }

    /**
     * The admin's delete: the client off the panel and the row gone for good. The orders that sold or renewed it stay
     * (the sales record) and point at nothing. A client the panel no longer has counts as deleted, and one already found
     * gone is not asked about again; with `$leavePanel` (the panel is out of reach) the panel is not contacted and the
     * client stays there for the admin to remove. True when this call removed the row.
     *
     * @throws ProviderException|ServiceBusyException
     */
    public function delete(Subscription $subscription, bool $leavePanel = false): bool
    {
        return $this->holding($subscription, function (Lease $lease) use ($subscription, $leavePanel): bool {
            if (!$leavePanel && $subscription->status !== SubscriptionStatus::Deleted) {
                try {
                    $this->held($lease, $subscription->server, static fn(ProviderInterface $panel) => $panel->deleteClient($subscription->remote_name));
                } catch (NotFoundException) {
                    // Deleted on the panel already (by hand, or by the panel's own clean-up).
                }
            }

            $deleted = $this->db->transaction(static function () use ($subscription): bool {
                // The foreign key would let the orders go by itself, but a database cascade is no statement the change
                // feed sees: written here, the orders screen follows.
                Order::query()->where('subscription_id', $subscription->id)->update(['subscription_id' => null]);

                return $subscription->newModelQuery()->whereKey($subscription->id)->delete() === 1;
            });
            if ($deleted) {
                $this->logger->info('Deleted subscription {id} ({name}) from {server}{left}', [
                    'id' => $subscription->id,
                    'name' => $subscription->remote_name,
                    'server' => $subscription->server->name,
                    'left' => $leavePanel ? ' (its client was left on the panel)' : '',
                ]);
            }

            return $deleted;
        });
    }

    /**
     * Run `$work` holding the service: a lease on its row, as long as a call to its panel — or to `$others`, a move's
     * target — may take. A second change of the same service waits a few seconds for the first, then gives up with
     * ServiceBusyException; once it gets the lease it works on the row as the first left it.
     *
     * @template T
     * @param \Closure(Lease): T $work
     * @param-immediately-invoked-callable $work
     * @return T
     * @throws ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    private function holding(Subscription $subscription, \Closure $work, Server ...$others): mixed
    {
        $seconds = self::holdSeconds($subscription->server, ...$others);
        for ($try = 1; ($lease = Lease::take($subscription, $seconds)) === null; $try++) {
            if (!$subscription->newModelQuery()->whereKey($subscription->id)->exists()) {
                throw (new ModelNotFoundException())->setModel(Subscription::class, [$subscription->id]);
            }
            if ($try >= self::WAIT_TRIES) {
                throw new ServiceBusyException();
            }
            $this->sleeper->usleep(self::WAIT_MICROSECONDS);
        }

        try {
            $subscription->refresh();

            return $work($lease);
        } finally {
            $lease->release();
        }
    }

    /**
     * Read the held service from its panel and keep the answer (apply()) — what a change that adds to it starts from.
     *
     * @throws NotFoundException when the panel no longer has the client and one is `$required`
     * @throws ProviderException|ServiceBusyException
     */
    private function read(Subscription $subscription, Lease $lease, bool $required = true): ?ClientInfo
    {
        $observed = now();
        $client = $this->held($lease, $subscription->server, static fn(ProviderInterface $panel): ?ClientInfo => $panel->findClient($subscription->remote_name));
        $this->apply($subscription, $client, $observed, $lease);
        if ($client === null && $required) {
            throw new NotFoundException("The panel no longer has client {$subscription->remote_name} of subscription #{$subscription->id}.");
        }

        return $client;
    }

    /**
     * Send the terms to the held service's client (resetting its counters first when the terms start afresh) and record
     * the panel's answer together with what the change decides itself (`$own`). With `$complete`, the row and the
     * order's completion are written in one transaction.
     *
     * @param array<string, mixed> $own
     * @param (\Closure(Subscription): void)|null $complete
     * @throws ProviderException|ServiceBusyException
     */
    private function applyTerms(Subscription $subscription, ServiceTerms $terms, Lease $lease, array $own, ?\Closure $complete = null): void
    {
        $observed = now();
        if ($terms->resetCounters) {
            $this->held($lease, $subscription->server, static fn(ProviderInterface $panel) => $panel->resetClientTraffic($subscription->remote_name));
        }
        $spec = $this->spec($subscription->user, $subscription->remote_name, $terms->totalBytes, $terms->expiry, $terms->ipLimit);
        $client = $this->held($lease, $subscription->server, static fn(ProviderInterface $panel): ClientInfo => $panel->updateClient($spec));

        if ($complete === null) {
            $this->record($subscription, $client, $observed, $lease, $own);

            return;
        }
        $this->db->transaction(function () use ($subscription, $client, $observed, $lease, $own, $complete): void {
            $this->record($subscription, $client, $observed, $lease, $own);
            $complete($subscription);
        });
    }

    /**
     * One call to the panel of a held service — or of the server it moves to —, the lease renewed first for as long as a
     * call may take. A holder that lost the lease meanwhile (it stalled past its time and another change took the
     * service) stops before the call with ServiceBusyException: the panel has not taken this change (at most its first
     * step, a counters reset, which the next try reads back).
     *
     * @template T
     * @param \Closure(ProviderInterface): T $call
     * @param-immediately-invoked-callable $call
     * @return T
     * @throws ProviderException|ServiceBusyException
     */
    private function held(Lease $lease, Server $server, \Closure $call): mixed
    {
        if (!$lease->renew()) {
            throw new ServiceBusyException();
        }

        return $this->onPanel($server, $call);
    }

    /**
     * Write the panel's answer onto the row (values()). Under a lease the write is the holder's. Otherwise it only lands
     * over the row as it was read — same server, client and status, nobody holding it, its copy not fresher than this
     * answer — so a sync that read the panel before a renewal cannot overwrite it. A client the panel no longer has
     * (`$client` null) marks the row deleted. True when the row was written.
     */
    private function apply(Subscription $subscription, ?ClientInfo $client, Carbon $observedAt, ?Lease $lease = null): bool
    {
        $values = self::values($subscription, $client, $observedAt);
        if ($lease !== null) {
            return $lease->write($values);
        }

        $written = Lease::whereFree($subscription->newModelQuery()
            ->whereKey($subscription->id)
            ->where('server_id', $subscription->getRawOriginal('server_id'))
            ->where('remote_name', $subscription->getRawOriginal('remote_name'))
            ->where('status', $subscription->getRawOriginal('status')))
            ->where(static fn(Builder $q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $observedAt))
            ->update($values) === 1;
        if ($written) {
            $subscription->forceFill($values)->syncOriginal();
        }

        return $written;
    }

    /**
     * Write a change's outcome onto the held row: the panel's answer plus what the change decided itself (`$own`). If the
     * lease was lost since the last panel call, it is written by the row's key anyway and logged: the panel has the
     * change, so the row must say so — undoing or retrying it would apply it twice.
     *
     * @param array<string, mixed> $own
     * @throws ModelNotFoundException when the service is gone meanwhile
     */
    private function record(Subscription $subscription, ClientInfo $client, Carbon $observedAt, Lease $lease, array $own = []): void
    {
        $values = self::values($subscription, $client, $observedAt, $own);
        if ($lease->write($values)) {
            return;
        }

        if ($subscription->newModelQuery()->whereKey($subscription->id)->update($values) !== 1) {
            throw (new ModelNotFoundException())->setModel(Subscription::class, [$subscription->id]);
        }
        $subscription->forceFill($values)->syncOriginal();
        $this->logger->warning('Subscription {id} ({name}): the change outlasted its hold on the service, and another change took it meanwhile; what the panel answered was written all the same', [
            'id' => $subscription->id,
            'name' => $subscription->remote_name,
        ]);
    }

    /**
     * The row's new values from the panel's answer (mirror(); deleted when the panel no longer has the client) and the
     * change's own (`$own`), with the status that follows from both.
     *
     * @param array<string, mixed> $own
     * @return array<string, mixed>
     */
    private static function values(Subscription $subscription, ?ClientInfo $client, Carbon $observedAt, array $own = []): array
    {
        if ($client === null) {
            return ['status' => SubscriptionStatus::Deleted, 'last_synced_at' => $observedAt];
        }
        $values = array_replace(self::mirror($subscription, $client, $observedAt), $own);
        $values['status'] = self::statusOf((clone $subscription)->forceFill($values));

        return $values;
    }

    /**
     * The columns that mirror the panel: counters, quota, link, and the term — the deadline once the panel started the
     * clock, the term still waiting for the first connection, or none. A deadline the panel moved (an extension made
     * there) moves a queued renewal period with it.
     *
     * @return array<string, mixed>
     */
    private static function mirror(Subscription $row, ClientInfo $client, Carbon $observedAt): array
    {
        $values = [
            'upload_bytes' => $client->uploadBytes,
            'download_bytes' => $client->downloadBytes,
            'traffic_limit_bytes' => $client->totalBytes,
            // The panel's subscription server may move (another domain, port or path): the kept link follows. A panel
            // that stopped serving links leaves the last one known.
            'subscription_url' => $client->subscriptionUrl ?? $row->subscription_url,
            'last_synced_at' => $observedAt,
        ];

        $deadline = $client->expiry->deadline();
        if ($deadline === null) {
            $pending = $client->expiry->pendingSeconds();

            // The term waiting for the first connection, in whole hours (a part of one counts as one).
            return $values + ['expires_at' => null, 'starts_at' => null, 'duration_hours' => $pending !== null ? (int) ceil($pending / ServiceTerms::HOUR) : 0];
        }

        $expiresAt = Carbon::instance($deadline);
        $was = $row->expires_at;
        if ($was !== null && $was->equalTo($expiresAt)) {
            return $values;
        }

        return $values + [
            'expires_at' => $expiresAt,
            'starts_at' => ServiceTerms::startedAt($row, $expiresAt, $observedAt),
            'period_ends_at' => $was !== null ? $row->period_ends_at?->copy()->addSeconds($expiresAt->getTimestamp() - $was->getTimestamp()) : $row->period_ends_at,
        ];
    }

    /**
     * The status once the panel's numbers are in: an active service whose time or traffic ran out has ended; an ended one
     * the panel extended runs again. Support's switch and a deleted row stay as they are.
     */
    private static function statusOf(Subscription $row): SubscriptionStatus
    {
        $ended = $row->isExpiredByTime() || $row->isExpiredByTraffic();

        return match ($row->status) {
            SubscriptionStatus::Active, SubscriptionStatus::Expired => $ended ? SubscriptionStatus::Expired : SubscriptionStatus::Active,
            default => $row->status,
        };
    }

    /**
     * One call to the server's panel, its outcome recorded on the server (ServerHealth::contacted()).
     *
     * @template T
     * @param \Closure(ProviderInterface): T $call
     * @param-immediately-invoked-callable $call
     * @return T
     * @throws ProviderException
     */
    private function onPanel(Server $server, \Closure $call): mixed
    {
        try {
            $result = $call($this->providers->forServer($server));
        } catch (ProviderException $e) {
            $this->health->contacted($server, $e);

            throw $e;
        }
        $this->health->contacted($server, null);

        return $result;
    }

    /** Delete a client just made for something that fell through; a failure is logged, never thrown over the reason. */
    private function takeBack(Server $server, string $name): void
    {
        try {
            $this->providers->forServer($server)->deleteClient($name);
        } catch (ProviderException $e) {
            $this->logger->error('Could not take back client {name} on {server}; delete it by hand: {message}', ['name' => $name, 'server' => $server->name, 'message' => $e->getMessage()]);
        }
    }

    /** The client a customer's service is on its panel: their Telegram id and comment, and what it is given. */
    private function spec(User $user, string $name, int $totalBytes, Expiry $expiry, int $ipLimit): ClientSpec
    {
        return new ClientSpec(
            name: $name,
            totalBytes: $totalBytes,
            expiry: $expiry,
            ipLimit: $ipLimit,
            telegramId: $user->telegram_id,
            comment: $this->naming->comment($user),
        );
    }
}
