<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\ChangeFeed;
use App\Core\Database\Lease;
use App\Core\Database\Transitions;
use App\Core\Exceptions\ValidationException;
use App\Core\Support\Sleeper;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\DeliveryTakenOverException;
use App\Modules\Orders\Exceptions\ServiceChangedHandsException;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Contracts\ProviderInterface;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\DTO\ClientLink;
use App\Modules\Providers\DTO\ClientSpec;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\PanelApiException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Exceptions\UnsupportedOperationException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Providers\Services\ServerHealth;
use App\Modules\Providers\Support\PanelConnection;
use App\Modules\Subscriptions\DTO\TestTerms;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\ExtraUnavailableException;
use App\Modules\Subscriptions\Exceptions\MoveException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Exceptions\ServiceNotReadException;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Subscriptions\Models\MigrationLeftover;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\TestAccount;
use App\Modules\Users\Models\User;
use App\Support\LocalTime;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Every change of a service on its panel — created for a purchase or as a free test, claimed by its link, renewed, given
 * days and traffic, sold extras, started on a queued period, re-linked, switched, moved, deleted — and every read back.
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

    /**
     * The longest one call to a panel may take, with the margin: holdSeconds() of a server whose panel has the longest
     * timeout a server may give it (PanelConnection::MAX_TIMEOUT).
     */
    private const LONGEST_CALL = PanelConnection::MAX_TIMEOUT * self::REQUESTS_A_CALL + self::HOLD_MARGIN;

    /** Seconds a delivery's order goes untouched before a call to a panel touches it (beat()). */
    private const BEAT_EVERY = 60;

    /**
     * The longest a delivery at work leaves its order untouched: BEAT_EVERY, then one call as long as LONGEST_CALL. One
     * whose order went untouched longer belongs to a process that died (OrderService::STALE_PROCESSING_MINUTES).
     */
    public const LONGEST_SILENCE = self::BEAT_EVERY + self::LONGEST_CALL;

    /** How long a change waits for another change of the same service: tries, and the pause between them (5 s in all). */
    private const WAIT_TRIES = 20;
    private const WAIT_MICROSECONDS = 250_000;

    /** Names a new client is tried under while its panel has a client by each already (named()). */
    private const NAME_TRIES = 10;

    /** Why rotateLink() refuses: the service does not run, or its panel cannot rotate. */
    public const ROTATE_INACTIVE = 'لینک فقط برای سرویس فعال عوض می‌شود.';
    public const ROTATE_UNSUPPORTED = 'سرور این سرویس تغییر لینک را پشتیبانی نمی‌کند.';

    /** The order whose delivery the calls to panels under way are for (delivering()); null outside one. */
    private ?Order $delivering = null;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ServerHealth $health,
        private readonly ServerSelector $selector,
        private readonly ClientNaming $naming,
        private readonly RenewalSettings $renewal,
        private readonly CustomerExtras $extras,
        private readonly Sleeper $sleeper,
        private readonly ConnectionInterface $db,
        private readonly ChangeFeed $feed,
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
     * Create the client for a paid purchase and its service row — of its plan, on every inbound the plan sells on the
     * server chosen, or one the customer built (Order::package()), on every inbound its server sells —, named as
     * ClientNaming::forPurchase() says, or the pattern's next while its panel has a client by that name (named()).
     * `$complete` (the order's completion) runs in the transaction that writes the row; if that transaction fails, the
     * client is deleted from the panel again so a retry leaves nothing behind.
     *
     * @param \Closure(Subscription): void $complete
     * @throws NoServerAvailableException when it cannot be delivered now, or the panel gave no link
     * @throws TermTooLongException when its term would end past the last day the shop keeps: no panel asked
     * @throws ProviderException
     */
    public function provision(Order $order, \Closure $complete): Subscription
    {
        return $this->delivering($order, function () use ($order, $complete): Subscription {
            $package = $order->package() ?? throw new \LogicException("Order #{$order->id} has nothing to provision.");
            // Its term starts at the first connection.
            $term = ServiceTerms::newTerm($package->hours);
            $plan = $order->plan;
            $user = $order->user;
            ['server' => $server, 'inbounds' => $inbounds] = $plan !== null
                ? $this->selector->resolve($plan, $order->server_id, paid: true)
                : $this->selector->builtOn($order->server ?? throw new \LogicException("Built order #{$order->id} has no server."));

            $create = fn(string $name): Subscription => $this->create($server, $inbounds, $this->spec($user, $name, $package->bytes, $term, $package->ipLimit), [
                'user_id' => $user->id,
                'plan_id' => $plan?->id,
                'duration_hours' => $package->hours,
                // «تمدید خودکار» starts as the admin set it for new services, on a plan that can renew itself at all.
                'auto_renew' => $plan !== null && $this->renewal->autoRenewByDefault() && $this->renewal->autoRenews($plan),
            ], static function (Subscription $subscription) use ($order, $complete): void {
                $order->forceFill(['subscription_id' => $subscription->id])->save();
                // Whoever words the delivery reads the service off this order.
                $order->setRelation('subscription', $subscription);
                $complete($subscription);
            });
            $onPanel = $this->hasClient($server);
            $subscription = $this->named($this->naming->forPurchase($order, $server, $onPanel), $create, $onPanel, fn(): string => $this->naming->next($user, $server));

            $this->logger->info('Provisioned subscription {id} for user {user} on {server}', ['id' => $subscription->id, 'user' => $user->id, 'server' => $server->name]);

            return $subscription;
        });
    }

    /**
     * Make a new client named `$name` through `$create` — and, while its panel refuses it for a client there by that name
     * already (`$onPanel`, asked only then), under `$next`'s name instead, NAME_TRIES names in all: the shop knows the
     * names of the services it keeps (ClientNaming skips those), not of a client made on the panel by hand, nor of
     * another order's asking the same name delivered at the same moment. A buyer's own name, and the name a moved
     * service keeps, give way to the pattern so.
     *
     * @template T
     * @param \Closure(string): T $create
     * @param \Closure(string): bool $onPanel
     * @param \Closure(): string $next
     * @return T
     * @throws PanelApiException when the panel refuses it for another reason, or the names run out
     * @throws NoServerAvailableException|ProviderException|ServiceBusyException
     */
    private function named(string $name, \Closure $create, \Closure $onPanel, \Closure $next): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $create($name);
            } catch (PanelApiException $e) {
                // One that failed after the panel took it (the read of it after) refused no name: the client is there.
                if ($try >= self::NAME_TRIES || $e->mayHaveBeenDone() || !$onPanel($name)) {
                    throw $e;
                }
                $this->logger->info('Client name {name} is taken on its panel by a client the shop does not keep; the next is tried', ['name' => $name]);
                $name = $next();
            }
        }
    }

    /** @return \Closure(string): bool Whether the server's panel has a client by that name (one findClient()). */
    private function hasClient(Server $server): \Closure
    {
        return fn(string $name): bool => $this->onPanel($server, static fn(ProviderInterface $panel): bool => $panel->findClient($name) !== null);
    }

    /**
     * Create a free test account's client — on every inbound the server sells, named by the test pattern (named()) — and
     * its row: no plan, marked a test, its term as the server's test terms say (from the first connection, or from now).
     * `$issued` runs in the transaction that writes the row; if that transaction fails, the client is deleted from the
     * panel again.
     *
     * @param Collection<int, ServerInbound> $inbounds
     * @param \Closure(Subscription): void $issued
     * @throws NoServerAvailableException when the panel serves no subscription links, or gave no link
     * @throws ProviderException
     */
    public function provisionTest(User $user, Server $server, Collection $inbounds, TestTerms $terms, \Closure $issued): Subscription
    {
        $next = fn(): string => $this->naming->next($user, $server, test: true);
        $create = fn(string $name): Subscription => $this->create($server, $inbounds, $this->spec($user, $name, $terms->bytes, $terms->expiry(), 0), ['user_id' => $user->id, 'is_test' => true, 'duration_hours' => $terms->hours], $issued);
        $subscription = $this->named($next(), $create, $this->hasClient($server), $next);

        $this->logger->info('Made test account {id} for user {user} on {server}', ['id' => $subscription->id, 'user' => $user->id, 'server' => $server->name]);

        return $subscription;
    }

    /**
     * The client a link the customer sent is for on the server's panel (ProviderInterface::findClientByLink()), the
     * contact recorded on the server; null when the panel has none.
     *
     * @throws ProviderException
     */
    public function findByLink(Server $server, ClientLink $link): ?ClientInfo
    {
        return $this->onPanel($server, static fn(ProviderInterface $panel): ?ClientInfo => $panel->findClientByLink($link));
    }

    /**
     * Delete a client a move left behind on the server's panel (move()'s leave(), ServerMigrations) — unless a service of
     * any shop is by that name on that server now (moved back there, or made under the name since): it is that service's
     * then, and stays. The service the client was (`$of`, while it lasts) is held meanwhile, so a move of it back there
     * cannot come between the look and the delete; while another change holds it, nothing is done (ServiceBusyException,
     * at once: a client left behind can wait). True when the panel no longer has the client (gone already counts); false
     * when a service keeps it.
     *
     * @throws ProviderException|ServiceBusyException
     */
    public function removeLeftover(Server $server, string $name, ?Subscription $of): bool
    {
        $lease = $of !== null ? Lease::take($of, self::holdSeconds($of->server, $server)) : null;
        if ($of !== null && $lease === null) {
            throw new ServiceBusyException();
        }

        try {
            if (Subscription::acrossShops()->where('server_id', $server->id)->where('remote_name', $name)->where('status', '!=', SubscriptionStatus::Deleted->value)->exists()) {
                return false;
            }
            try {
                $this->onPanel($server, static fn(ProviderInterface $panel) => $panel->deleteClient($name));
            } catch (NotFoundException) {
                // Gone from the panel already.
            }

            return true;
        } finally {
            $lease?->release();
        }
    }

    /**
     * Keep a client the shop did not make as the customer's service — one they claimed by its link (ServiceClaims): its
     * row as the panel answered (`$client`, asked at `$observedAt`) through values(), marked claimed, the customer's as
     * any new owner's (newOwner()), its IP limit the panel's, and no plan unless `$carried` brings one (a stale record of
     * the customer's: its plan and «تمدید خودکار»).
     * - Ended by time or traffic, it comes ended, to renew — whether or not the panel switched it off (3x-ui switches a
     *   client off by itself once it is used up) —, and its removal (ServiceCleanup) counts from the claim, not from an
     *   end long past.
     * - Switched off on the panel while it still runs, it is kept switched off, as support's switch says.
     * - A deadline still ahead is its term, counted from now: the hours left to it. When its clock started the panel
     *   does not say: `starts_at` stays unknown (null), never the claim.
     * `$claimed` runs in the transaction that writes the row; a client the shop keeps already fails on the row's unique key.
     *
     * @param \Closure(Subscription): void $claimed
     * @param array{plan_id?: int|null, auto_renew?: bool, is_test?: bool} $carried
     * @throws UniqueConstraintViolationException when the shop keeps the client already
     */
    public function adopt(User $user, Server $server, ClientInfo $client, Carbon $observedAt, \Closure $claimed, array $carried = []): Subscription
    {
        $link = $client->subscriptionUrl ?? throw new \LogicException("Client {$client->name} on {$server->name} has no subscription link to keep.");
        $deadline = $client->expiry->deadline();
        $hoursLeft = $deadline !== null && $deadline->getTimestamp() > $observedAt->getTimestamp() ? (int) ceil(($deadline->getTimestamp() - $observedAt->getTimestamp()) / ServiceTerms::HOUR) : 0;

        $subscription = $this->db->transaction(static function () use ($user, $server, $client, $link, $observedAt, $claimed, $carried, $hoursLeft): Subscription {
            $subscription = new Subscription($carried + self::newOwner($user) + [
                'server_id' => $server->id,
                'remote_name' => $client->name,
                'subscription_url' => $link,
                'status' => SubscriptionStatus::Active,
                'ip_limit' => $client->ipLimit ?? 0,
                'duration_hours' => $hoursLeft,
                'claimed_at' => $observedAt,
                // What endedAt() keeps for one that comes ended.
                'ended_at' => $observedAt,
            ]);
            $values = ['starts_at' => null] + self::values($subscription, $client, $observedAt);
            if (!$client->enabled && $values['status'] === SubscriptionStatus::Active) {
                $values = ['status' => SubscriptionStatus::Disabled, 'disabled_at' => $observedAt] + $values;
            }
            $subscription->fill($values)->save();
            $claimed($subscription);

            return $subscription;
        });

        $this->logger->info('User {user} claimed client {name} on {server} as subscription {id}', ['user' => $user->id, 'name' => $client->name, 'server' => $server->name, 'id' => $subscription->id]);

        return $subscription;
    }

    /**
     * Apply the renewal order's package — its plan's, or the one it was built with — to its service by the order's method
     * (ServiceTerms::renewal()), from what the panel says right now — the traffic left and the deadline are the panel's.
     * What it gives is kept on the order (ServiceTerms::given()) in the transaction that writes the row, with
     * `$complete`. A service handed to another customer since the order was claimed for delivery gets nothing: a
     * transfer holds the service as this does (ServiceTransfers::transfer()), so the owner read under the hold stands.
     *
     * @param \Closure(Subscription): void $complete
     * @throws NotFoundException when the panel no longer has the client: there is nothing to renew
     * @throws ServiceChangedHandsException|TermTooLongException
     * @throws ProviderException|ServiceBusyException
     */
    public function renew(Order $order, \Closure $complete): Subscription
    {
        $subscription = $order->subscription ?? throw new \LogicException("Renewal order #{$order->id} has no subscription.");

        return $this->delivering($order, fn(): Subscription => $this->holding($subscription, function (Lease $lease) use ($order, $subscription, $complete): Subscription {
            self::stillTheirs($order, $subscription);
            $package = $order->package() ?? $subscription->plan?->package() ?? throw new \LogicException("Renewal order #{$order->id} has nothing to give.");
            $this->read($subscription, $lease);

            $terms = ServiceTerms::renewal($subscription, $package, $this->renewal->carriesTraffic(), $order->renewal_method ?? RenewalMethod::Extend, CustomerExtras::devicesBought($subscription));
            // What it gives — less than nothing for a reset that replaced more — is kept on the order with its completion,
            // and what a package without a term or a quota replaced: its refund undoes exactly that.
            $given = $terms->given($subscription) + ['gave_plan' => $subscription->plan_id === null && $order->plan_id !== null];
            $own = ($order->plan_id !== null ? ['plan_id' => $order->plan_id] : []) + ['status' => SubscriptionStatus::Active, 'disabled_at' => null];
            // Renewed, it runs: its client switched on.
            $this->applyTerms($subscription, $terms, $lease, $own + $terms->columns(), enabled: true, complete: static function (Subscription $renewed) use ($order, $given, $complete): void {
                $order->forceFill($given)->save();
                $complete($renewed);
            });

            return $subscription;
        }));
    }

    /**
     * Undo what a refunded renewal or extra gave its service (ServiceTerms::takeBack()), from what the panel says right
     * now, as far as the service still has it — and give back what a reset replaced beyond what it gave, and a term or a
     * quota a renewal ended (undone()). Once: the order is marked taken back — with what it hands on to the orders after
     * it — in the transaction that writes the row, and read again under the service's hold, so a second refund of it
     * takes nothing and one handed something by another refund meanwhile takes that too. Its client stays switched on or
     * off as the panel has it, and the panel is not asked when nothing changes. True when something was taken back, now
     * or before; false when nothing was — nothing of it left on the service, or the panel no longer has the client.
     *
     * @throws ProviderException|ServiceBusyException
     * @throws ServiceChangedHandsException when the service went to another customer: nothing of theirs is taken
     * @throws ModelNotFoundException when the service is gone
     */
    public function undo(Order $order, Subscription $subscription): bool
    {
        return $this->holding($subscription, function (Lease $lease) use ($order, $subscription): bool {
            // As it stands under the hold: taken back meanwhile, or handed something by another refund since it was read.
            $order->refresh();
            if ($order->taken_back_at !== null) {
                return true;
            }
            self::stillTheirs($order, $subscription);
            $client = $this->read($subscription, $lease, required: false);
            if ($client === null) {
                return false;
            }

            // The renewals after it, and the extra days after it that started a new term (ServiceTerms::extra()).
            $later = self::standingAfter($order, $subscription, [OrderType::Renewal, OrderType::ExtraTime])->get()
                ->filter(static fn(Order $after): bool => $after->type === OrderType::Renewal || $after->reset_term)->values();
            [$terms, $own] = self::undone($order, $subscription, $later);
            $complete = static function () use ($order, $later): void {
                $order->forceFill(['taken_back_at' => now()])->save();
                foreach ($later as $after) {
                    $after->save(); // what it was handed, if anything
                }
            };
            if ($own === [] && !$terms->changes($subscription)) {
                $this->db->transaction($complete);

                return false;
            }

            $this->applyTerms($subscription, $terms, $lease, $own + $terms->columns(), enabled: $client->enabled, complete: $complete);
            $this->logger->info('Subscription {id} gave back what refunded order {order} gave it', ['id' => $subscription->id, 'order' => $order->id]);

            return true;
        });
    }

    /**
     * What a refund of `$order` takes back from the service as its row has it now — the terms, and the plan (`$own`) —,
     * with what it hands on written onto `$later` (the renewals delivered after it and still standing, and the extra
     * days after it that started a new term, in that order; unsaved):
     * - a renewal's term and traffic as delivered (`given_hours`, `given_bytes`: less than nothing for a reset that
     *   replaced more than it gave, given back; its plan's as it is now for one delivered before they were kept), an
     *   extra's days (`given_hours` once they started a new term), traffic or devices;
     * - but nothing of a part a later order started afresh — the term (`reset_term`: a renewal's, or extra days for a
     *   service whose time ran out), the traffic count (`reset_traffic`), the device limit (`reset_ip_limit`, or the
     *   next period begun: ServiceTerms::devicesGone()) —: that part is handed on to the first such order, added to what
     *   it gave (devices: taken off the limit it replaced, while that counted them), so its own refund takes it with its
     *   own;
     * - what only the last renewal standing undoes goes back once none delivered after it stands, and is handed on to
     *   the next one until then, in place of what that one would undo of it: the plan, a term or a quota it gave a
     *   service that had none (`gave_plan`, `gave_term`, `gave_quota`: no plan again, never ending, unlimited); the term
     *   or the quota a package without one replaced (`term_was`, `expires_was`, `quota_was`: back whole, less what was
     *   handed on to it, the extras delivered after it and still standing on top — extrasAfter(), their refunds take
     *   them later —; handed on, with what was handed on to it less the extras between the two); and the device limit
     *   it set at once (limitPutBack(), with the moment the limit had last gone back to a package's), which the next one
     *   set at once replaced (its `ip_limit_was`, `ip_limit_reset_was`) — and, set at once, only while no period queued
     *   since began; one it queued behind the period in use goes back while no other was queued after it, else the one
     *   after replaces what it did.
     *
     * @param Collection<int, Order> $later
     * @return array{ServiceTerms, array<string, mixed>}
     */
    private static function undone(Order $order, Subscription $subscription, Collection $later): array
    {
        $renewal = $order->type === OrderType::Renewal;
        $hours = $order->given_hours ?? ($renewal ? $order->plan->duration_hours ?? 0 : (int) $order->days * ServiceTerms::HOURS_A_DAY);
        $bytes = $order->given_bytes ?? ($renewal ? $order->plan?->trafficBytes() ?? 0 : (int) $order->traffic_bytes);
        $devices = $renewal ? 0 : (int) $order->devices;
        $renewals = $later->filter(static fn(Order $after): bool => $after->type === OrderType::Renewal)->values();
        $next = $renewal ? $renewals->first() : null;
        $last = $renewal && $next === null;
        // A term or a quota it ended goes back whole (or to the next renewal), never to an order that restarted it.
        $keepsTerm = $order->term_was !== null;
        $keepsQuota = $order->quota_was !== null;
        $term = $keepsTerm ? null : $later->first(static fn(Order $after): bool => $after->reset_term);
        $traffic = $keepsQuota ? null : $later->first(static fn(Order $after): bool => $after->reset_traffic);
        $term?->forceFill(['given_hours' => (int) $term->given_hours + $hours]);
        $traffic?->forceFill(['given_bytes' => (int) $traffic->given_bytes + $bytes]);

        if ($next !== null) {
            $between = $keepsTerm || $keepsQuota ? self::extrasAfter($order, $subscription, until: $next) : [0, 0];
            $next->forceFill(['gave_plan' => $next->gave_plan || $order->gave_plan] + match (true) {
                $order->gave_term => ['gave_term' => true, 'term_was' => null, 'expires_was' => null],
                $keepsTerm => ['gave_term' => false, 'term_was' => $order->term_was, 'expires_was' => $order->expires_was, 'reset_term' => true, 'given_hours' => $hours - $between[0]],
                default => [],
            } + match (true) {
                $order->gave_quota => ['gave_quota' => true, 'quota_was' => null],
                $keepsQuota => ['gave_quota' => false, 'quota_was' => $order->quota_was, 'reset_traffic' => true, 'given_bytes' => $bytes - $between[1]],
                default => [],
            });
        }
        $row = $subscription;
        [$extraHours, $extraBytes] = [0, 0];
        if ($last && ($keepsTerm || $keepsQuota)) {
            [$extraHours, $extraBytes] = self::extrasAfter($order, $subscription);
            $row = (clone $subscription)->forceFill(
                ($keepsTerm ? ['expires_at' => $order->expires_was, 'duration_hours' => $order->term_was] : [])
                + ($keepsQuota ? ['traffic_limit_bytes' => $subscription->usedBytes() + (int) $order->quota_was] : []),
            );
        }
        $takenHours = match (true) {
            $keepsTerm => $last ? $hours - $extraHours : 0,
            $term !== null => 0,
            default => $hours,
        };
        $takenBytes = match (true) {
            $keepsQuota => $last ? $bytes - $extraBytes : 0,
            $traffic !== null => 0,
            default => $bytes,
        };
        $terms = ServiceTerms::takeBack($row, $takenHours, $takenBytes, $renewal, $devices, $order->fulfilled_at, noTerm: $last && $order->gave_term, noQuota: $last && $order->gave_quota);

        if ($devices > 0 && ServiceTerms::devicesGone($subscription, $order->fulfilled_at)) {
            // Gone with the limit a later renewal put back at once: off the limit it replaced, which counted them.
            $reset = $renewals->first(static fn(Order $after): bool => $after->reset_ip_limit && ($after->ip_limit_reset_was === null || $after->ip_limit_reset_was->lte($order->fulfilled_at)));
            if ($reset?->ip_limit_was !== null) {
                $reset->forceFill(['ip_limit_was' => ServiceTerms::lessDevices($reset->ip_limit_was, $devices)]);
            }
        }
        if ($renewal && $order->given_ip_limit !== null) {
            $terms = self::limitBack($order, $subscription, $renewals, $terms);
        }

        // The renewal that gave the service its plan: refunded, it has none again.
        $own = $last && $order->gave_plan && $subscription->plan_id !== null && (int) $subscription->plan_id === (int) $order->plan_id ? ['plan_id' => null, 'auto_renew' => false] : [];

        return [$terms, $own];
    }

    /**
     * `$terms` with the device limit the refunded renewal `$order` gave put back, or handed on to the renewal after it
     * that replaced it (undone()).
     *
     * @param Collection<int, Order> $later
     */
    private static function limitBack(Order $order, Subscription $subscription, Collection $later, ServiceTerms $terms): ServiceTerms
    {
        $given = (int) $order->given_ip_limit;
        if ($order->ip_limit_queued) {
            $after = $later->first(static fn(Order $after): bool => $after->given_ip_limit !== null);
            if ($after === null) {
                return $terms->limitPutBack($given, $order->ip_limit_was, queued: true);
            }
            if ($after->ip_limit_queued && $after->ip_limit_was === $given) {
                $after->forceFill(['ip_limit_was' => $order->ip_limit_was]);
            }

            return $terms;
        }

        $after = $later->first(static fn(Order $after): bool => $after->given_ip_limit !== null && !$after->ip_limit_queued);
        if ($after === null) {
            // Still the service's while no period queued since began (its limit put back to a package's then).
            $since = $subscription->ip_limit_reset_at;

            return $since === null || $since->lte($order->fulfilled_at) ? $terms->limitPutBack($given, $order->ip_limit_was, queued: false, reset: $order->reset_ip_limit, resetWas: $order->ip_limit_reset_was) : $terms;
        }
        // The next set at once replaced it — unless a period that began between them had replaced it first.
        if ($after->ip_limit_was !== null && $order->ip_limit_was !== null && ($after->ip_limit_reset_was === null || $after->ip_limit_reset_was->lte($order->fulfilled_at))) {
            $after->forceFill([
                'ip_limit_was' => ServiceTerms::limitUndone($after->ip_limit_was, $given, $order->ip_limit_was),
                'ip_limit_reset_was' => $order->ip_limit_reset_was,
                'reset_ip_limit' => $after->reset_ip_limit || $order->reset_ip_limit,
            ]);
        }

        return $terms;
    }

    /**
     * The service's orders of `$types` that stand delivered (not taken back) after `$order` — and before `$until`, when
     * given —, in the order they were delivered (by `fulfilled_at`, then id).
     *
     * @param list<OrderType> $types
     * @return Builder<Order>
     */
    private static function standingAfter(Order $order, Subscription $subscription, array $types, ?Order $until = null): Builder
    {
        $query = Order::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('type', array_map(static fn(OrderType $type): string => $type->value, $types))
            ->where('status', OrderStatus::Fulfilled->value)
            ->whereNull('taken_back_at')
            ->where(static fn(Builder $later) => $later
                ->where('fulfilled_at', '>', $order->fulfilled_at)
                ->orWhere(static fn(Builder $tie) => $tie->where('fulfilled_at', $order->fulfilled_at)->where('id', '>', $order->id)));
        if ($until !== null) {
            $query->where(static fn(Builder $earlier) => $earlier
                ->where('fulfilled_at', '<', $until->fulfilled_at)
                ->orWhere(static fn(Builder $tie) => $tie->where('fulfilled_at', $until->fulfilled_at)->where('id', '<', $until->id)));
        }

        return $query->orderBy('fulfilled_at')->orderBy('id');
    }

    /**
     * The hours and bytes the extra days and traffic delivered after `$order` (and before `$until`) and still standing
     * gave, as their refunds take them back: a term or a quota put back whole as it was before `$order` keeps them on top,
     * so each of those refunds still finds its own.
     *
     * @return array{int, int}
     */
    private static function extrasAfter(Order $order, Subscription $subscription, ?Order $until = null): array
    {
        $extras = self::standingAfter($order, $subscription, [OrderType::ExtraTime, OrderType::ExtraTraffic], $until)->get();

        return [
            (int) $extras->sum(static fn(Order $extra): int => $extra->type === OrderType::ExtraTime ? $extra->given_hours ?? (int) $extra->days * ServiceTerms::HOURS_A_DAY : 0),
            (int) $extras->sum(static fn(Order $extra): int => $extra->type === OrderType::ExtraTraffic ? (int) $extra->traffic_bytes : 0),
        ];
    }

    /**
     * Refuse to deliver or take back `$order` once its service is another customer's: asked of the row as the service's
     * hold read it again — a transfer holds the service too, so it lands before or after the change, never during it.
     *
     * @throws ServiceChangedHandsException
     */
    private static function stillTheirs(Order $order, Subscription $subscription): void
    {
        if ((int) $subscription->user_id !== (int) $order->user_id) {
            throw new ServiceChangedHandsException();
        }
    }

    /**
     * Start the period a renewal was queued behind, once `period_ends_at` has passed: what remains is capped at the
     * renewed traffic and counted afresh, by the panel's counters now, and the device limit becomes the renewal's
     * (ServiceTerms::nextPeriod(), periodOver()). The panel is asked only when it has something to change: a cap, or a
     * limit it keeps (a period queued for its devices alone on a panel without IP limits is the row's alone). A client the
     * panel no longer has, or a service that used everything with its limit unchanged, has nothing to send: the queued
     * period is just over. One moved on since — days given — waits for its new end.
     *
     * @throws ProviderException|ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    public function startNextPeriod(Subscription $subscription): void
    {
        $this->holding($subscription, function (Lease $lease) use ($subscription): void {
            if ($subscription->period_ends_at === null || $subscription->period_ends_at->isFuture()) {
                return; // started meanwhile, or moved on
            }

            $limit = $subscription->next_period_ip_limit ?? $subscription->ip_limit;
            $asks = $subscription->next_period_bytes !== null || ($limit !== $subscription->ip_limit && $this->providers->capabilities($subscription->server->driver)->ipLimit);
            $client = $asks ? $this->read($subscription, $lease, required: false) : null;
            $over = ServiceTerms::periodOver($subscription);
            $terms = $client !== null ? ServiceTerms::nextPeriod($subscription) : null;
            if ($client === null || $terms === null) {
                $lease->write($over);

                return;
            }

            $this->applyTerms($subscription, $terms, $lease, $over, enabled: $client->enabled);
            $this->logger->info('Subscription {id} began its renewed period with {bytes} bytes and {devices} devices', ['id' => $subscription->id, 'bytes' => $terms->totalBytes, 'devices' => $terms->ipLimit]);
        });
    }

    /**
     * Add days and traffic to a service — its share of a grant, or support's extension — worked out from the row as the
     * panel last reported it (ServiceTerms::grant()). Its client is switched on, unless support switched the service off.
     *
     * @throws TermTooLongException when the days would end it past the last day the shop keeps: no panel asked
     * @throws ProviderException|ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    public function grant(Subscription $subscription, int $days, int $bytes): void
    {
        $this->holding($subscription, function (Lease $lease) use ($subscription, $days, $bytes): void {
            $terms = ServiceTerms::grant($subscription, $days, $bytes);
            $this->applyTerms($subscription, $terms, $lease, $terms->columns(), enabled: $subscription->status !== SubscriptionStatus::Disabled);
        });
    }

    /**
     * Deliver the extra traffic, time or devices an order bought onto its service (ServiceTerms::extra()), from what
     * the panel says right now, its client switched on (an ended service runs again). The service must still be able to
     * take it by the panel's numbers (CustomerExtras::refusal()): switched off by support since, or ended on the other
     * count, the order fails with the reason; handed to another customer since, it gets nothing. `$complete` runs in the
     * transaction that writes the row — with, for days that started a new term, that restart kept on the order.
     *
     * @param \Closure(Subscription): void $complete
     * @throws NotFoundException when the panel no longer has the client
     * @throws ExtraUnavailableException|ServiceChangedHandsException|TermTooLongException
     * @throws ProviderException|ServiceBusyException
     */
    public function addExtra(Order $order, \Closure $complete): Subscription
    {
        $subscription = $order->subscription ?? throw new \LogicException("Extra order #{$order->id} has no subscription.");
        $kind = ExtraKind::of($order->type) ?? throw new \LogicException("Order #{$order->id} buys no extra.");

        return $this->delivering($order, fn(): Subscription => $this->holding($subscription, function (Lease $lease) use ($order, $subscription, $kind, $complete): Subscription {
            self::stillTheirs($order, $subscription);
            $this->read($subscription, $lease);
            $problem = $this->extras->refusal($subscription, $kind);
            if ($problem !== null) {
                throw new ExtraUnavailableException($problem);
            }

            $terms = ServiceTerms::extra($subscription, (int) $order->days, (int) $order->traffic_bytes, (int) $order->devices);
            // Days that start a new term are kept on the order as a renewal's restart is: an earlier order's refund
            // takes nothing of that term, and hands its hours on to this one's (undone()).
            $given = $terms->restartsTerm ? ['given_hours' => $terms->givenHours, 'reset_term' => true] : [];
            $this->applyTerms($subscription, $terms, $lease, $terms->columns(), enabled: true, complete: static function (Subscription $extended) use ($order, $given, $complete): void {
                if ($given !== []) {
                    $order->forceFill($given)->save();
                }
                $complete($extended);
            });
            $this->logger->info('Subscription {id} got {extra} from order {order}', ['id' => $subscription->id, 'extra' => $kind->words($kind->quantityOf($order)), 'order' => $order->id]);

            return $subscription;
        }));
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
     * short —; a panel that cannot list its clients is asked for each. One the panel fails to answer about is logged and
     * holds up no other; a panel out of reach stops the server's turn. Returns how many services it read.
     *
     * @throws ProviderException when the panel cannot be asked
     */
    public function syncServer(Server $server): int
    {
        $subscriptions = Subscription::active($server->subscriptions()->getQuery())->orderBy('id')->get();
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
            if ($client !== null) {
                $this->apply($subscription, $client, $observed);

                continue;
            }
            try {
                $this->inspect($subscription);
            } catch (ProviderException $e) {
                if ($e->unavailable()) {
                    throw $e;
                }
                $this->logger->warning('Could not read subscription {id} ({name}) on {server}: {message}', ['id' => $subscription->id, 'name' => $subscription->remote_name, 'server' => $server->name, 'message' => $e->getMessage()]);
            }
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
     * on it — stops working; limits, expiry and counters stay. The row learns the new link; the caller sends it — to the
     * customer it read the service as, so a service handed to another since, or no longer running, is not rotated.
     *
     * @throws ValidationException 422 on `status`: the service does not run, its panel cannot rotate, or it changed hands
     * @throws ProviderException|ServiceBusyException
     */
    public function rotateLink(Subscription $subscription): Subscription
    {
        if (!$this->rotatable($subscription)) {
            throw ValidationException::on('status', $subscription->isActive() ? self::ROTATE_UNSUPPORTED : self::ROTATE_INACTIVE);
        }
        $owner = (int) $subscription->user_id;

        return $this->holding($subscription, function (Lease $lease) use ($subscription, $owner): Subscription {
            if ((int) $subscription->user_id !== $owner) {
                throw ValidationException::on('status', ServiceTransfers::MOVED_ON);
            }
            if (!$subscription->isActive()) {
                throw ValidationException::on('status', self::ROTATE_INACTIVE);
            }
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
     * The columns a service takes with a new owner, whichever way it changes hands — handed on (ServiceTransfers) or
     * added to their account by its link (adopt()): theirs, «تمدید خودکار» off (their wallet is theirs to commit), and
     * nothing stamped as told (the reminders, the short wallet's notice: they have heard nothing yet). Written in the
     * transaction that changes the owner, with its report; relabel() follows the commit.
     *
     * @return array{user_id: int, auto_renew: false, renewal_notified_at: null, expiry_reminded_at: null, traffic_reminded_at: null}
     */
    public static function newOwner(User $owner): array
    {
        return ['user_id' => $owner->id, 'auto_renew' => false, 'renewal_notified_at' => null, 'expiry_reminded_at' => null, 'traffic_reminded_at' => null];
    }

    /**
     * Once the service has a new owner (newOwner(), or its customer's account merged into another): its client on the
     * panel takes their Telegram id and comment (ClientNaming::comment()), everything else as the panel has it — read,
     * then updated with the same quota, term and IP limit, switched on or off as it was. A client gone from its panel
     * marks the row deleted.
     *
     * Never fails the change of owner, which is committed already: a panel in backoff (not asked, so nobody waits out
     * its timeout), a panel that fails it, another change holding the service too long, or the service deleted
     * meanwhile is logged — the next change of the service on its panel writes them all the same, since every spec()
     * reads the service's customer.
     */
    public function relabel(Subscription $subscription): void
    {
        if ($subscription->server->isBackingOff()) {
            $this->notRelabelled($subscription, 'its panel is in backoff');

            return;
        }

        try {
            $this->holding($subscription, function (Lease $lease) use ($subscription): void {
                $client = $this->read($subscription, $lease, required: false);
                if ($client === null) {
                    return;
                }

                $observed = now();
                // As the panel has it, the owner aside: a lost answer could not tell if it took this (update()).
                $spec = $this->spec($subscription->user, $subscription->remote_name, $client->totalBytes, $client->expiry, $subscription->ip_limit, $client->enabled);
                $updated = $this->held($lease, $subscription->server, static fn(ProviderInterface $panel): ClientInfo => $panel->updateClient($spec));
                $this->record($subscription, $updated, $observed, $lease);
                $this->logger->info('Subscription {id} ({name}) now names user {user} on {server}', ['id' => $subscription->id, 'name' => $subscription->remote_name, 'user' => $subscription->user_id, 'server' => $subscription->server->name]);
            });
        } catch (ProviderException|ServiceBusyException|ModelNotFoundException $e) {
            $this->notRelabelled($subscription, $e->getMessage());
        }
    }

    /**
     * relabel() each of the customer's services still on a panel — only those of `$ids` when given —, oldest first: once
     * their clients should name them otherwise (another account merged into theirs, a Telegram account linked or taken
     * off).
     *
     * @param list<int>|null $ids
     */
    public function relabelServicesOf(User $owner, ?array $ids = null): void
    {
        $services = Subscription::acrossShops()->where('user_id', $owner->id)->where('status', '!=', SubscriptionStatus::Deleted->value)
            ->when($ids !== null, static fn(Builder $query) => $query->whereKey($ids))->orderBy('id')->get();
        foreach ($services as $service) {
            $this->relabel($service->setRelation('user', $owner));
        }
    }

    /** A relabel() that did not reach the panel, logged: the change of owner stands. */
    private function notRelabelled(Subscription $subscription, string $why): void
    {
        $this->logger->warning('Subscription {id} ({name}) has a new owner, but its client on {server} does not name them yet: {message}', [
            'id' => $subscription->id,
            'name' => $subscription->remote_name,
            'server' => $subscription->server->name,
            'message' => $why,
        ]);
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
     * same deadline, or the same pending term) — under the name ClientNaming::nameOn() gives it, or the customer's next
     * while the target's panel has a client by that name already (named()) —, then the old client deleted and the row
     * pointed at the new one. The old panel is read first so what is left is current. With `$leavePrevious` (that panel
     * is out of reach) it is not contacted: the row's last numbers are carried over and the old client stays there. A
     * delete the old panel refuses takes the new client back — nothing moved.
     *
     * A call that may have been done though no answer came (ProviderException::mayHaveBeenDone(): a timeout) is asked
     * about on its panel, so a customer is never left with no service, nor a client made twice: the old client's delete —
     * gone, the move stands; still there, the new client goes back; no answer, the move stands and the old client is left
     * behind —, and the new client's making (makeOn()). Each client the move leaves on a panel — the old one it did not
     * delete, in the transaction that points the row at the new one so its record stands or falls with the move; a new
     * one a panel did not say it made, or would not take back — is written down (leave()), of the job that moved it
     * (`$by`) or of none, to be removed once that panel answers (ServerMigrations): a client the customer still has the
     * link of would be a second service.
     *
     * A failure to point the row at the new client takes it back only while it is nobody's (the service was deleted
     * meanwhile) or the old one is still the customer's (`$leavePrevious`); once the old one is gone, or may be, the new
     * one is all the customer has, and it is kept.
     *
     * Only an active service moves, and only while it has something left — or, with `$switchedOff`, one support switched
     * off too: its new client is made switched off, and it stays so. With `$from`, only while it is still on that server
     * (a batch chose it there; someone may have moved it since).
     *
     * @throws NoServerAvailableException when the target cannot take the service
     * @throws MoveException naming the step that failed
     * @throws ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    public function move(Subscription $subscription, Server $target, bool $leavePrevious, bool $switchedOff = false, ?Server $from = null, ?ServerMigration $by = null): void
    {
        $this->holding($subscription, function (Lease $lease) use ($subscription, $target, $leavePrevious, $switchedOff, $from, $by): void {
            $left = static fn(Server $server, string $name) => self::leave($server, $name, $subscription, $by);
            $source = $subscription->server;
            $previous = $subscription->remote_name;
            if ($source->id === $target->id) {
                throw MoveException::service('این سرویس همین حالا روی این سرور است.');
            }
            if ($from !== null && $source->id !== $from->id) {
                throw MoveException::service("سرویس {$previous} دیگر روی سرور «{$from->name}» نیست.");
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
            $movable = $subscription->isActive() || ($switchedOff && $subscription->status === SubscriptionStatus::Disabled);
            if (!$movable || $subscription->isExpiredByTime() || $subscription->isExpiredByTraffic()) {
                throw MoveException::service("سرویس {$subscription->remote_name} دیگر فعال نیست؛ چیزی برای انتقال نمانده.");
            }

            // The new client on the target: by the name ClientNaming::nameOn() gives it — or, while the target's panel has
            // a client by that name made by hand, the customer's next (named()) —, made switched off for one support
            // switched off, so it never works on the target.
            $keys = $inbounds->pluck('remote_key')->values()->all();
            $spec = fn(string $name): ClientSpec => $this->spec($subscription->user, $name, $subscription->remainingBytes() ?? 0, ServiceTerms::expiryOf($subscription), $subscription->ip_limit, $subscription->isActive());
            $observed = now();
            try {
                $client = $this->named(
                    $this->naming->nameOn($subscription, $target),
                    fn(string $name): ClientInfo => $this->makeOn($target, $keys, $spec($name), $lease, $left),
                    fn(string $name): bool => $this->held($lease, $target, static fn(ProviderInterface $panel): bool => $panel->findClient($name) !== null),
                    fn(): string => $this->naming->next($subscription->user, $target, $subscription->is_test),
                );
            } catch (ProviderException $e) {
                throw MoveException::targetServer($target, $e);
            }
            if ($client->subscriptionUrl === null) {
                $this->takeBack($target, $client->name, $left);

                throw MoveException::targetServer($target, 'برای کلاینت لینک اشتراک نساخت؛ سرور اشتراک پنلش را بررسی کنید.');
            }

            // The old client off its panel; if that panel refuses, the new one goes again and nothing moved. A delete
            // that may have been done all the same is asked about there: gone, the move stands; still there, the new one
            // goes again; no answer, the move stands and the old client is left behind (taking the new one back could
            // leave the customer with neither).
            $leftBehind = $leavePrevious;
            if (!$leavePrevious) {
                try {
                    $this->held($lease, $source, static fn(ProviderInterface $panel) => $panel->deleteClient($previous));
                } catch (NotFoundException) {
                    // Gone from the old panel already.
                } catch (ProviderException|ServiceBusyException $e) {
                    $still = $e instanceof ProviderException && $e->mayHaveBeenDone() ? $this->stillOn($source, $previous) : true;
                    if ($still === true) {
                        $this->takeBack($target, $client->name, $left);

                        throw $e instanceof ProviderException ? MoveException::previousServer($source, $e) : $e;
                    }
                    $leftBehind = $still === null;
                }
            }

            try {
                $this->db->transaction(function () use ($subscription, $client, $observed, $lease, $target, $leftBehind, $left, $source, $previous): void {
                    $this->record($subscription, $client, $observed, $lease, ['server_id' => $target->id, 'remote_name' => $client->name]);
                    if ($leftBehind) {
                        $left($source, $previous);
                    }
                });
            } catch (ModelNotFoundException $e) {
                // The service was deleted while it moved: its new client is nobody's.
                $this->takeBack($target, $client->name, static fn(Server $server, string $name) => self::leave($server, $name, null, $by));

                throw $e;
            } catch (\Throwable $e) {
                if ($leavePrevious) {
                    // The old client is where it was, and the row still names it: nothing moved.
                    $this->takeBack($target, $client->name, $left);
                } else {
                    // The old client is gone, or may be: the new one is the only service the customer has.
                    $this->logger->error('Subscription {id} was moved to {to} as client {name}, but the shop could not point it there: {message}; its client on {from} is gone or may be, so the new one is kept', [
                        'id' => $subscription->id,
                        'to' => $target->name,
                        'name' => $client->name,
                        'message' => $e->getMessage(),
                        'from' => $source->name,
                    ]);
                }

                throw $e;
            }
            $subscription->setRelation('server', $target);

            $this->logger->info('Moved subscription {id} from {from} to {to}{left}', [
                'id' => $subscription->id,
                'from' => $source->name,
                'to' => $target->name,
                'left' => $leavePrevious ? ' (the old client was left on the previous server)' : '',
            ]);
            if ($leftBehind && !$leavePrevious) {
                $this->logger->warning('{server} did not say whether it deleted client {name} of moved subscription {id}; it is removed once that panel answers', [
                    'server' => $source->name,
                    'name' => $previous,
                    'id' => $subscription->id,
                ]);
            }
        }, $target);
    }

    /**
     * Write down a client a move left on the server's panel (`migration_leftovers`), to be removed once that panel
     * answers (ServerMigrations::clean()): of the service `$of` — none when it is gone —, by the job `$by` when a job
     * moved it.
     */
    private static function leave(Server $server, string $name, ?Subscription $of, ?ServerMigration $by): void
    {
        MigrationLeftover::query()->create([
            'migration_id' => $by?->id,
            'server_id' => $server->id,
            'subscription_id' => $of?->id,
            'remote_name' => $name,
        ]);
    }

    /**
     * Make a client on the server's panel: a purchase's or a free test's (create()), or a moved service's on its target,
     * under the move's `$lease`. A failure that may have come after the panel made it (ProviderException::mayHaveBeenDone():
     * no answer came, or reading the client back failed, however it failed) has the panel asked: a client there by that
     * name as it was asked — its quota and its switch, nothing used — is the one made, and kept, so no second is made
     * under another name (a delivery's retry draws the next) and none is left on the panel unrecorded; one as asked but
     * for its switch is taken back (takeBack()). One the panel cannot be asked about is left behind (`$left`, else logged
     * for the admin).
     *
     * @param list<string> $keys
     * @param (\Closure(Server, string): void)|null $left
     * @throws ProviderException|ServiceBusyException
     */
    private function makeOn(Server $server, array $keys, ClientSpec $spec, ?Lease $lease = null, ?\Closure $left = null): ClientInfo
    {
        $create = static fn(ProviderInterface $panel): ClientInfo => $panel->createClient($keys, $spec);
        try {
            return $lease !== null ? $this->held($lease, $server, $create) : $this->onPanel($server, $create);
        } catch (ProviderException $e) {
            if (!$e->mayHaveBeenDone()) {
                throw $e;
            }
            try {
                $made = $this->onPanel($server, static fn(ProviderInterface $panel): ?ClientInfo => $panel->findClient($spec->name));
            } catch (ProviderException) {
                if ($left !== null) {
                    $left($server, $spec->name);
                } else {
                    $this->logger->warning('{server} did not say whether it made client {name}; delete it there by hand if it is there and unused', ['server' => $server->name, 'name' => $spec->name]);
                }

                throw $e;
            }
            if ($made === null || $made->totalBytes !== $spec->totalBytes || $made->uploadBytes + $made->downloadBytes > 0) {
                throw $e;
            }
            if ($made->enabled !== $spec->enabled) {
                // Made, but not switched as asked (a connector that could not switch it off once made): taken back, so
                // no client of a service support switched off works unrecorded.
                $this->takeBack($server, $spec->name, $left);

                throw $e;
            }
            $this->logger->info('{server} made client {name} though it did not answer; it is kept', ['server' => $server->name, 'name' => $spec->name]);

            return $made;
        }
    }

    /** Whether the server's panel still has a client by that name; null when it cannot say (the contact recorded on the server). */
    private function stillOn(Server $server, string $name): ?bool
    {
        try {
            return $this->hasClient($server)($name);
        } catch (ProviderException) {
            return null;
        }
    }

    /**
     * Delete a service: the client off the panel and the row gone for good. The orders that sold or renewed it stay (the
     * sales record) and point at nothing; a test's record (`test_accounts`) stays for its quota. A client the panel no
     * longer has counts as deleted, and one already found gone is not asked about again; with `$leavePanel` (the panel is
     * out of reach) the panel is not contacted and the client stays there for the admin to remove. `$kept`, when given, is
     * asked of the row as it is removed, under its lock (remove()): true keeps it. True when this call removed the row.
     *
     * @param (\Closure(Subscription): bool)|null $kept
     * @throws ProviderException|ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    public function delete(Subscription $subscription, bool $leavePanel = false, ?\Closure $kept = null): bool
    {
        return $this->holding($subscription, fn(Lease $lease): bool => $this->remove($subscription, $lease, $leavePanel, $kept));
    }

    /**
     * Delete a service only while `$still` holds of it as its panel says now: held, read from its panel (unless it is
     * gone from it already), judged, then deleted as delete() does — and judged again as the row is removed, under its
     * lock (remove()), so an order opened for it while its panel deleted the client, which `$still` would have kept it
     * for, keeps it. What a timer removes on a rule (the test accounts' clean-up), so a change made since it chose the
     * service — days support gave, the customer's first connection — is never lost. True when this call removed the row.
     *
     * @param \Closure(Subscription): bool $still
     * @throws ProviderException|ServiceBusyException
     * @throws ModelNotFoundException when the service is gone
     */
    public function deleteIf(Subscription $subscription, \Closure $still): bool
    {
        return $this->holding($subscription, function (Lease $lease) use ($subscription, $still): bool {
            if ($subscription->status !== SubscriptionStatus::Deleted) {
                $this->read($subscription, $lease, required: false);
            }

            return $still($subscription) && $this->remove($subscription, $lease, leavePanel: false, kept: static fn(Subscription $held): bool => !$still($held));
        });
    }

    /**
     * The held service deleted (delete()); true when this call removed the row. Its row is locked first in the transaction
     * that removes it — as an order opened for it locks it (OrderService::holding()), so neither waits on the other's
     * locks the other way round, and one opened while the panel deleted the client is seen — and `$kept` asked then: true
     * keeps the row, marked deleted when this call took its client off the panel.
     *
     * @param (\Closure(Subscription): bool)|null $kept
     */
    private function remove(Subscription $subscription, Lease $lease, bool $leavePanel, ?\Closure $kept = null): bool
    {
        $offPanel = null;
        if (!$leavePanel && $subscription->status !== SubscriptionStatus::Deleted) {
            try {
                $this->held($lease, $subscription->server, static fn(ProviderInterface $panel) => $panel->deleteClient($subscription->remote_name));
            } catch (NotFoundException) {
                // Deleted on the panel already (by hand, or by the panel's own clean-up).
            }
            $offPanel = now();
        }

        $deleted = $this->db->transaction(static function () use ($subscription, $kept): ?bool {
            if ($subscription->newModelQuery()->whereKey($subscription->id)->lockForUpdate()->value('id') === null) {
                return false;
            }
            if ($kept !== null && $kept($subscription)) {
                return null;
            }
            // The foreign key would let the orders go by itself, but a database cascade is no statement the change feed
            // sees: written here, the orders and the test accounts screens follow.
            Order::query()->where('subscription_id', $subscription->id)->update(['subscription_id' => null]);
            TestAccount::query()->where('subscription_id', $subscription->id)->update(['subscription_id' => null]);

            return $subscription->newModelQuery()->whereKey($subscription->id)->delete() === 1;
        });
        if ($deleted === null) {
            if ($offPanel !== null) {
                $this->apply($subscription, null, $offPanel, $lease);
            }
            $this->logger->info('Subscription {id} ({name}) was kept as it was being deleted{left}', [
                'id' => $subscription->id,
                'name' => $subscription->remote_name,
                'left' => $offPanel !== null ? '; its client is off the panel' : '',
            ]);

            return false;
        }
        if ($deleted) {
            $this->logger->info('Deleted subscription {id} ({name}) from {server}{left}', [
                'id' => $subscription->id,
                'name' => $subscription->remote_name,
                'server' => $subscription->server->name,
                'left' => $leavePanel ? ' (its client was left on the panel)' : '',
            ]);
        }

        return $deleted;
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
     * Send the terms to the held service's client (resetting its counters first when the terms start afresh), switched
     * on or off as `$enabled` says, and record the panel's answer together with what the change decides itself
     * (`$own`). With `$complete`, the row and the order's completion are written in one transaction.
     *
     * @param array<string, mixed> $own
     * @param (\Closure(Subscription): void)|null $complete
     * @throws ProviderException|ServiceBusyException
     */
    private function applyTerms(Subscription $subscription, ServiceTerms $terms, Lease $lease, array $own, bool $enabled, ?\Closure $complete = null): void
    {
        $observed = now();
        if ($terms->resetCounters) {
            $this->held($lease, $subscription->server, static fn(ProviderInterface $panel) => $panel->resetClientTraffic($subscription->remote_name));
        }
        $spec = $this->spec($subscription->user, $subscription->remote_name, $terms->totalBytes, $terms->expiry, $terms->ipLimit, $enabled);
        $client = $this->update($subscription->server, $spec, $lease);

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
     * Send `$spec` to the held service's client. An update that may have been done though no answer came
     * (ProviderException::mayHaveBeenDone(): a timeout) has the panel asked: a client there just as it was sent — its
     * quota, term, switch and device limit (where the panel keeps one) — took it, and that answer is recorded, so a retry
     * never adds the same renewal, extra or grant on top of what the panel already has, nor a refund takes it back twice.
     * A panel that cannot be asked, or has the client otherwise, leaves the failure as it came.
     *
     * @throws ProviderException|ServiceBusyException
     */
    private function update(Server $server, ClientSpec $spec, Lease $lease): ClientInfo
    {
        try {
            return $this->held($lease, $server, static fn(ProviderInterface $panel): ClientInfo => $panel->updateClient($spec));
        } catch (ProviderException $e) {
            if (!$e->mayHaveBeenDone()) {
                throw $e;
            }
            try {
                // Asked whatever became of the lease meanwhile: the panel may have the change, which record() then writes.
                $client = $this->onPanel($server, static fn(ProviderInterface $panel): ?ClientInfo => $panel->findClient($spec->name));
            } catch (ProviderException) {
                throw $e;
            }
            if ($client === null || !self::took($client, $spec)) {
                throw $e;
            }
            $this->logger->info('{server} took the update of client {name} though it did not answer; it is recorded', ['server' => $server->name, 'name' => $spec->name]);

            return $client;
        }
    }

    /** Whether the client has what `$spec` sends it: its quota, term, switch, and device limit where its panel keeps one. */
    private static function took(ClientInfo $client, ClientSpec $spec): bool
    {
        return $client->totalBytes === $spec->totalBytes
            && $client->enabled === $spec->enabled
            && ($client->ipLimit === null || $client->ipLimit === $spec->ipLimit)
            && $client->expiry->deadline()?->getTimestamp() === $spec->expiry->deadline()?->getTimestamp()
            && $client->expiry->pendingSeconds() === $spec->expiry->pendingSeconds();
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
     * change's own (`$own`), with the status that follows from both and when it ended (endedAt()).
     *
     * @param array<string, mixed> $own
     * @return array<string, mixed>
     */
    private static function values(Subscription $subscription, ?ClientInfo $client, Carbon $observedAt, array $own = []): array
    {
        if ($client === null) {
            return ['status' => SubscriptionStatus::Deleted, 'ended_at' => $subscription->ended_at ?? $observedAt, 'last_synced_at' => $observedAt];
        }
        $values = array_replace(self::mirror($subscription, $client, $observedAt), $own);
        $row = (clone $subscription)->forceFill($values);
        $values['status'] = self::statusOf($row);
        $values['ended_at'] = self::endedAt($row->forceFill(['status' => $values['status']]), $subscription->ended_at, $observedAt);

        return $values;
    }

    /**
     * When the service ended, as it now stands: null while it runs; once it ended, when it was first found so — the
     * deadline it passed, else (its traffic used up) the moment the panel said it. Support's switch leaves it as it was.
     */
    private static function endedAt(Subscription $row, ?Carbon $was, Carbon $observedAt): ?Carbon
    {
        return match ($row->status) {
            SubscriptionStatus::Active => null,
            SubscriptionStatus::Expired => $was ?? ($row->expires_at !== null && $row->expires_at->lte($observedAt) ? $row->expires_at : $observedAt),
            default => $was,
        };
    }

    /**
     * The columns that mirror the panel: counters, quota, device limit (where the panel keeps one), link, and the term —
     * the deadline once the panel started the clock, the term still waiting for the first connection, or none. A
     * deadline the panel moved (an extension made there) moves a queued renewal period with it; a device limit changed
     * there is the service's from then on, so the next extra builds on it rather than putting the old back (a renewal
     * brings its package's). A deadline past the last moment the shop keeps (LocalTime::last(): a long term first
     * connected late, a client extended on its panel by hand) is kept as that moment — the database refuses a later one,
     * and the shop gives such a service no more time (ServiceTerms).
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
        ] + ($client->ipLimit !== null ? ['ip_limit' => $client->ipLimit] : []);

        $deadline = $client->expiry->deadline();
        if ($deadline === null) {
            $pending = $client->expiry->pendingSeconds();

            // The term waiting for the first connection, in whole hours (a part of one counts as one).
            return $values + ['expires_at' => null, 'starts_at' => null, 'duration_hours' => $pending !== null ? (int) ceil($pending / ServiceTerms::HOUR) : 0];
        }

        $expiresAt = LocalTime::tooLate($deadline) ? LocalTime::last() : Carbon::instance($deadline);
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
     * One call to the server's panel — a delivery under way showing it is alive first (beat()) —, its outcome recorded on
     * the server (ServerHealth::contacted()).
     *
     * @template T
     * @param \Closure(ProviderInterface): T $call
     * @param-immediately-invoked-callable $call
     * @return T
     * @throws ProviderException
     */
    private function onPanel(Server $server, \Closure $call): mixed
    {
        $this->beat();
        try {
            $result = $call($this->providers->forServer($server));
        } catch (ProviderException $e) {
            $this->health->contacted($server, $e);

            throw $e;
        }
        $this->health->contacted($server, null);

        return $result;
    }

    /**
     * Run `$work`, the delivery of `$order` (claimed for it by OrderService): each call to a panel on the way shows first
     * that the delivery is alive (beat()) — or stops it with DeliveryTakenOverException once another process has the order.
     *
     * @template T
     * @param \Closure(): T $work
     * @param-immediately-invoked-callable $work
     * @return T
     */
    private function delivering(Order $order, \Closure $work): mixed
    {
        $was = $this->delivering;
        $this->delivering = $order;
        try {
            return $work();
        } finally {
            $this->delivering = $was;
        }
    }

    /**
     * The delivery under way (delivering()) is alive: before a call to a panel that may take as long as LONGEST_CALL, its
     * order — untouched for BEAT_EVERY — is touched, its `updated_at` being what tells a claim a dead process left
     * (OrderService::isStale(), Transitions::reclaim()). Only while it is still being delivered and still has the moment
     * this delivery last wrote there: a claim taken over meanwhile (this one went quiet too long) stops it before the
     * call. Quietly (ChangeFeed::quietly()): no screen shows it, and no other rule reads it of an order being delivered
     * (an unpaid order's expiry reads a pending one's).
     *
     * @throws DeliveryTakenOverException
     */
    private function beat(): void
    {
        $order = $this->delivering;
        if ($order === null || $order->updated_at?->greaterThan(now()->subSeconds(self::BEAT_EVERY))) {
            return;
        }
        $column = $order->getUpdatedAtColumn();
        $now = $order->freshTimestamp();
        $touched = $this->feed->quietly(static fn(): int => $order->newModelQuery()
            ->whereKey($order->id)
            ->where('status', OrderStatus::Processing->value)
            ->where($column, $order->getRawOriginal($column))
            ->toBase()->update([$column => $now]));
        if ($touched !== 1) {
            throw new DeliveryTakenOverException($order->id);
        }
        $order->setUpdatedAt($now)->syncOriginalAttribute($column);
    }

    /**
     * Make the client on the server's `$inbounds` (makeOn(): one the panel made though no answer came is found and kept)
     * and its service row — `$row`'s own columns, the rest as the panel answered —, `$complete` in the transaction that
     * writes it. The customer gets one subscription link and nothing else: a panel that stopped serving links since its
     * last check gets no client, and one that answers without a link, or a transaction that fails, has the client taken
     * back.
     *
     * @param Collection<int, ServerInbound> $inbounds
     * @param array<string, mixed> $row
     * @param \Closure(Subscription): void $complete
     * @throws NoServerAvailableException|ProviderException
     */
    private function create(Server $server, Collection $inbounds, ClientSpec $spec, array $row, \Closure $complete): Subscription
    {
        if (!$this->onPanel($server, static fn(ProviderInterface $panel): bool => $panel->servesSubscriptions())) {
            throw new NoServerAvailableException("سرور اشتراک (Subscription) در پنل «{$server->name}» فعال نیست؛ آن را فعال کنید و سرور را دوباره بررسی کنید.");
        }

        $observed = now();
        $client = $this->makeOn($server, $inbounds->pluck('remote_key')->values()->all(), $spec);
        if ($client->subscriptionUrl === null) {
            $this->takeBack($server, $spec->name);

            throw new NoServerAvailableException("پنل «{$server->name}» برای کلاینت لینک اشتراک نساخت؛ سرور اشتراک پنل را بررسی کنید.");
        }

        try {
            return $this->db->transaction(static function () use ($server, $spec, $row, $client, $observed, $complete): Subscription {
                $subscription = new Subscription($row + [
                    'server_id' => $server->id,
                    'remote_name' => $client->name,
                    'status' => SubscriptionStatus::Active,
                    'ip_limit' => $spec->ipLimit,
                ]);
                $subscription->fill(self::mirror($subscription, $client, $observed))->save();
                $complete($subscription);

                return $subscription;
            });
        } catch (\Throwable $e) {
            $this->takeBack($server, $spec->name);

            throw $e;
        }
    }

    /**
     * Delete a client just made for something that fell through; a failure is never thrown over the reason — a move's
     * client is written down to be removed once that panel answers (`$left`), any other logged for the admin.
     *
     * @param (\Closure(Server, string): void)|null $left
     */
    private function takeBack(Server $server, string $name, ?\Closure $left = null): void
    {
        try {
            $this->providers->forServer($server)->deleteClient($name);
        } catch (NotFoundException) {
            // Gone from the panel already.
        } catch (ProviderException $e) {
            if ($left === null) {
                $this->logger->error('Could not take back client {name} on {server}; delete it by hand: {message}', ['name' => $name, 'server' => $server->name, 'message' => $e->getMessage()]);

                return;
            }
            $left($server, $name);
            $this->logger->warning('Could not take back client {name} on {server}; it is removed once that panel answers: {message}', ['name' => $name, 'server' => $server->name, 'message' => $e->getMessage()]);
        }
    }

    /** The client a customer's service is on its panel: their Telegram id and comment, and what it is given. */
    private function spec(User $user, string $name, int $totalBytes, Expiry $expiry, int $ipLimit, bool $enabled = true): ClientSpec
    {
        return new ClientSpec(
            name: $name,
            totalBytes: $totalBytes,
            expiry: $expiry,
            ipLimit: $ipLimit,
            telegramId: $user->telegram_id,
            comment: $this->naming->comment($user),
            enabled: $enabled,
        );
    }
}
