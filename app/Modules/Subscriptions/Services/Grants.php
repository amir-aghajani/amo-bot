<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Lease;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Bots\Models\Bot;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Providers\Exceptions\ConnectionException;
use App\Modules\Providers\Exceptions\NotFoundException;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Subscriptions\DTO\Gift;
use App\Modules\Subscriptions\Enums\GrantAudience;
use App\Modules\Subscriptions\Enums\GrantStatus;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\Grant;
use App\Modules\Subscriptions\Models\GrantPart;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Input;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * «افزودن زمان و حجم» (a server's page) and «هدیه همگانی» (the broadcasts page): days and traffic the admin gives the
 * running services — an outage made good, a gift — with a reason the customers read. A grant is for the services there
 * when it is issued, every bot's: on every server, the agents' only (what the bots of agents whose agency stands sold)
 * or one server's, that are
 * running when their turn comes (share()) — those still waiting for their first connection only when the admin ticks
 * `include_unstarted`; an ended one, or one switched off by support or on the panel itself, never. It is given as a part
 * per server it reaches, each worked through on its own, one service at a time past a cursor, by whoever holds the part's
 * lease — the screen while it is open, a request at a time, and Tasks\GrantsTask every minute — so it finishes however
 * many services there are, and a panel out of reach holds up its own server only (`waiting_reason`, until it answers;
 * meanwhile, while the server backs off, it is not even asked). Each service is claimed before its panel is touched, so
 * none is given twice. One part runs on a server at a time (the database's word); stopping a grant stops every part still
 * going, and the services reached keep what they got.
 */
final class Grants
{
    /** The grants a card lists. */
    private const HISTORY = 10;
    /** A grant that reaches no service. */
    private const NOBODY = ['running' => 0, 'unstarted' => 0];
    /** What the text columns hold. */
    private const TEXT_MAX = 500;

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Issue a grant — {days, traffic_gb, reason?, notify?, include_unstarted?, audience: all|agents|server, server_id?} —
     * by `$actor` (the owner): a part on every server with a service it could reach. process() works them through.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException on the amounts, the reason, `audience`, `server_id`, or on `grant`: a server it would
     *                             reach has a grant under way already, or no service could take it
     */
    public function start(array $input, Actor $actor): Grant
    {
        $values = $this->validate($input);
        $audience = GrantAudience::tryFrom(Input::text($input, 'audience')) ?? throw ValidationException::on('audience', 'به چه سرویس‌هایی برسد را انتخاب کنید.');
        $server = null;
        if ($audience === GrantAudience::Server) {
            $server = Server::query()->find(Input::integer($input, 'server_id') ?? 0) ?? throw ValidationException::on('server_id', 'سرور را انتخاب کنید.');
        }
        $serverIds = $server !== null
            ? [$server->id]
            : self::forAudience(Subscription::active(Subscription::acrossShops()), $audience)->distinct()->orderBy('server_id')->pluck('server_id')->map(intval(...))->all();

        try {
            return $this->db->transaction(function () use ($values, $audience, $server, $serverIds, $actor): Grant {
                $grant = Grant::query()->create($values + [
                    'audience' => $audience,
                    'upto_subscription_id' => (int) Subscription::acrossShops()->max('id'),
                    'reviewer' => $actor->reviewer,
                ]);
                foreach ($serverIds as $serverId) {
                    $total = self::candidates($grant, $serverId)->count();
                    if ($total > 0) {
                        $grant->parts()->create(['server_id' => $serverId, 'status' => GrantStatus::Running, 'total' => $total]);
                    }
                }
                if (!$grant->parts()->exists()) {
                    throw ValidationException::on('grant', $server !== null ? 'روی این سرور سرویس فعالی نیست که این زمان یا حجم به آن برسد.' : 'سرویس فعالی نیست که این زمان یا حجم به آن برسد.');
                }

                $this->logger->info('Grant {id} issued: {days} days and {bytes} bytes on {servers} servers', ['id' => $grant->id, 'days' => $grant->days, 'bytes' => $grant->traffic_bytes, 'servers' => $grant->parts()->count()]);

                return $grant;
            });
        } catch (UniqueConstraintViolationException) {
            // A part already runs on one of its servers (the database keeps one per server).
            $busy = GrantPart::running()->whereIn('server_id', $serverIds)->with('server')->first();

            throw ValidationException::on('grant', sprintf('روی سرور «%s» افزودن زمان و حجم دیگری در حال انجام است؛ صبر کنید تمام شود یا آن را متوقف کنید.', $busy->server->name ?? ''));
        }
    }

    /** Work on the grant's parts still going until `$until` (a unix time with fraction), one server after another. */
    public function run(Grant $grant, float $until): void
    {
        foreach ($grant->parts()->where('status', GrantStatus::Running->value)->with('server')->get() as $part) {
            if (microtime(true) >= $until) {
                return;
            }
            $this->process($part->setRelation('grant', $grant), $until);
        }
    }

    /** Every part under way, oldest first, until `$until` — the scheduler's turn, for the parts no screen is working on. */
    public function runAll(float $until): void
    {
        foreach (GrantPart::running()->with(['grant', 'server'])->oldest('id')->get() as $part) {
            if (microtime(true) >= $until) {
                return;
            }
            $this->process($part, $until);
        }
    }

    /**
     * Work on the part until it is done or `$until` (a unix time with fraction) passes, while no one else does: one
     * service at a time, each claimed before its panel is touched — held as long as a service's change is
     * (ProvisioningService::holdSeconds()): every step renews it, and a turn is one call to the panel between two steps.
     * True when this call finished it.
     */
    public function process(GrantPart $part, float $until): bool
    {
        $server = $part->server ?? throw new \LogicException("Grant part #{$part->id} has no server.");
        $lease = Lease::take($part, ProvisioningService::holdSeconds($server), static fn(Builder $q) => $q->where('status', GrantStatus::Running->value));
        if ($lease === null) {
            return false;
        }

        try {
            while (microtime(true) < $until) {
                $subscription = self::candidates($part->grant, $part->server_id)
                    ->where('id', '>', $part->last_subscription_id)
                    ->with('server')
                    ->oldest('id')
                    ->first();
                if ($subscription === null) {
                    return $this->finish($part, $lease);
                }
                if (!$this->give($part, $subscription, $lease)) {
                    return false;
                }
            }

            return false;
        } finally {
            $lease->release();
        }
    }

    /** Stop every part of the grant still going — the services reached keep what they got. True when this call stopped one. */
    public function cancel(Grant $grant): bool
    {
        $stopped = GrantPart::running()->where('grant_id', $grant->id)->update(self::stopped()) > 0;
        if ($stopped) {
            $this->logger->info('Grant {id} cancelled', ['id' => $grant->id]);
        }

        return $stopped;
    }

    /** Stop the grant on one server (from its page); its other servers go on. True when this call stopped it. */
    public function cancelPart(GrantPart $part): bool
    {
        $stopped = GrantPart::running()->whereKey($part->id)->update(self::stopped()) === 1;
        if ($stopped) {
            $this->logger->info('Grant {id} cancelled on server {server}', ['id' => $part->grant_id, 'server' => $part->server_id]);
        }

        return $stopped;
    }

    /**
     * Whom a new grant would reach, by what the shop last knew of its services: everyone's, the agents', and on each
     * server with services — from one grouped read of them (tally()), however many servers.
     *
     * @return array{all: array{running: int, unstarted: int}, agents: array{running: int, unstarted: int}, servers: list<array{id: int, name: string, running: int, unstarted: int}>}
     */
    public function audience(): array
    {
        $all = $agents = self::NOBODY;
        $servers = [];
        $agentBots = Bot::activeAgents()->pluck('id')->map(intval(...))->all();
        foreach (self::tally(Subscription::active(Subscription::acrossShops())) as ['server' => $server, 'bot' => $bot, 'reach' => $reach]) {
            $all = self::together($all, $reach);
            // The agents' services: what the bots of agents whose agency stands sold (forAudience()).
            $agents = in_array($bot, $agentBots, true) ? self::together($agents, $reach) : $agents;
            $servers[$server] = self::together($servers[$server] ?? self::NOBODY, $reach);
        }
        $names = Server::query()->whereIn('id', array_keys($servers))->oldest('id')->pluck('name', 'id');

        return [
            'all' => $all,
            'agents' => $agents,
            'servers' => $names->map(static fn(string $name, int $id): array => ['id' => $id, 'name' => $name] + $servers[$id])->values()->all(),
        ];
    }

    /**
     * Whom a grant on the server would reach, by what the shop last knew of its services there — every bot's: the running
     * ones, and those still waiting for their first connection (the admin's tick). The panel has the last word when it
     * runs: a customer may have connected since the shop last asked.
     *
     * @return array{running: int, unstarted: int}
     */
    public function reach(Server $server): array
    {
        $reach = self::NOBODY;
        foreach (self::tally(Subscription::active(Subscription::acrossShops())->where('server_id', $server->id)) as $row) {
            $reach = self::together($reach, $row['reach']);
        }

        return $reach;
    }

    /** @return list<array<string, mixed>> The latest grants, the newest first (the broadcasts page). */
    public function history(): array
    {
        return Grant::query()->with('parts.server')->latest('id')->limit(self::HISTORY)->get()->map($this->present(...))->values()->all();
    }

    /** @return list<array<string, mixed>> The latest grants' parts on the server, the newest first (its page). */
    public function historyOn(Server $server): array
    {
        return GrantPart::query()->where('server_id', $server->id)->with('grant')->latest('id')->limit(self::HISTORY)->get()->map($this->presentPart(...))->values()->all();
    }

    /** @return array<string, mixed> The grant with its parts' numbers added up, and each part (its parts and their servers loaded). */
    public function present(Grant $grant): array
    {
        $parts = $grant->parts;
        $sum = static fn(string $tally): int => (int) $parts->sum($tally);
        $server = $grant->audience === GrantAudience::Server ? $parts->first()?->server : null;

        return [
            'id' => $grant->id,
            'days' => $grant->days,
            'traffic_bytes' => $grant->traffic_bytes,
            'reason' => $grant->reason,
            'notify' => $grant->notify,
            'include_unstarted' => $grant->include_unstarted,
            'audience' => $grant->audience->value,
            'server' => $server !== null ? ['id' => $server->id, 'name' => $server->name] : null,
            'status' => $grant->status()->value,
            'total' => $sum('total'),
            'granted' => $sum('granted'),
            'skipped' => $sum('skipped'),
            'failed' => $sum('failed'),
            'parts' => $parts->map(static fn(GrantPart $part): array => [
                'id' => $part->id,
                'server' => ['id' => $part->server_id, 'name' => $part->server->name ?? ''],
                'status' => $part->status->value,
                'total' => $part->total,
                'granted' => $part->granted,
                'skipped' => $part->skipped,
                'failed' => $part->failed,
                'waiting_reason' => $part->waiting_reason,
                'last_failure' => $part->last_failure,
            ])->values()->all(),
            'reviewer' => $grant->reviewer,
            'created_at' => $grant->created_at->toIso8601String(),
            'finished_at' => $grant->finishedAt()?->toIso8601String(),
        ];
    }

    /**
     * A part as its server's page shows it: the grant's terms, and how far it got there. `mass_grant_id` is the grant's
     * when it reaches beyond this server — on the broadcasts page too.
     *
     * @return array<string, mixed>
     */
    public function presentPart(GrantPart $part): array
    {
        $grant = $part->grant;

        return [
            'id' => $part->id,
            'mass_grant_id' => $grant->audience === GrantAudience::Server ? null : $grant->id,
            'agents_only' => $grant->audience === GrantAudience::Agents,
            'days' => $grant->days,
            'traffic_bytes' => $grant->traffic_bytes,
            'reason' => $grant->reason,
            'notify' => $grant->notify,
            'include_unstarted' => $grant->include_unstarted,
            'status' => $part->status->value,
            'total' => $part->total,
            'granted' => $part->granted,
            'skipped' => $part->skipped,
            'failed' => $part->failed,
            'waiting_reason' => $part->waiting_reason,
            'last_failure' => $part->last_failure,
            'reviewer' => $grant->reviewer,
            'created_at' => $grant->created_at->toIso8601String(),
            'finished_at' => $part->finished_at?->toIso8601String(),
        ];
    }

    /**
     * The next service's turn: its share given — the customer told, when the grant says so — or it passed by. False when
     * this call must stop: the panel is out of reach or the service busy (the part waits at this service), or the part is
     * no longer this call's (stopped, or taken over after its lease ran out).
     */
    private function give(GrantPart $part, Subscription $subscription, Lease $lease): bool
    {
        $server = $subscription->server;
        if ($server->isBackingOff()) {
            return $this->wait($part, $lease, (string) $server->last_error);
        }
        // Held through the read that comes, as through every step: a worker at it keeps the part.
        if (!$lease->renew()) {
            return false;
        }

        try {
            $client = $this->provisioning->inspect($subscription);
        } catch (ProviderException $e) {
            return $e->unavailable()
                ? $this->wait($part, $lease, ProviderErrorPresenter::describe($e))
                : $lease->increment('failed', ['last_subscription_id' => $subscription->id, 'waiting_reason' => null, 'last_failure' => $this->failure($part, $subscription, $e)]);
        }

        $share = $client !== null ? self::share($part->grant, $subscription, $client) : null;
        if ($share === null) {
            return $lease->increment('skipped', ['last_subscription_id' => $subscription->id, 'waiting_reason' => null]);
        }

        // Claimed before the panel is touched: whatever happens next, it is never given twice.
        $previous = $part->last_subscription_id;
        if (!$lease->write(['last_subscription_id' => $subscription->id, 'waiting_reason' => null])) {
            return false;
        }

        [$days, $bytes] = $share;
        try {
            $this->provisioning->grant($subscription, $days, $bytes);
        } catch (ServiceBusyException) {
            // Another change of the service is under way: back to it on the next turn.
            $lease->move('last_subscription_id', $subscription->id, $previous);

            return false;
        } catch (NotFoundException) {
            // Gone from the panel since it was read.
            GrantPart::query()->whereKey($part->id)->increment('skipped');

            return true;
        } catch (ProviderException $e) {
            // A failure that may have come after the panel took the update (a timeout, a connection cut mid-way) is
            // counted, not tried again: the service must not be given twice.
            if (!($e instanceof ConnectionException ? $e->failure->beforeRequest() : $e->unavailable())) {
                GrantPart::query()->whereKey($part->id)->increment('failed', 1, ['last_failure' => $this->failure($part, $subscription, $e)]);

                return true;
            }

            // The update never reached the panel: back to this service once it answers.
            $lease->move('last_subscription_id', $subscription->id, $previous);

            return $this->wait($part, $lease, ProviderErrorPresenter::describe($e));
        }

        GrantPart::query()->whereKey($part->id)->increment('granted');
        if ($part->grant->notify) {
            $this->notifier->serviceGranted($subscription, $days, $bytes, $part->grant->reason);
        }

        return true;
    }

    /**
     * What the service takes of the grant — the days when it has a term, the traffic when it has a quota — by the panel's
     * word just read, or null when the grant is not for it: no longer running (ended, or switched off on the panel
     * itself), still waiting for its first connection when the admin did not tick those in, or given nothing it can take.
     *
     * @return array{int, int}|null [days, bytes]
     */
    private static function share(Grant $grant, Subscription $subscription, ClientInfo $client): ?array
    {
        if (!$subscription->isActive() || !$client->enabled || ($subscription->awaitsFirstUse() && !$grant->include_unstarted)) {
            return null;
        }

        $days = $subscription->hasTerm() ? $grant->days : 0;
        $bytes = $subscription->traffic_limit_bytes > 0 ? $grant->traffic_bytes : 0;

        return $days === 0 && $bytes === 0 ? null : [$days, $bytes];
    }

    /**
     * The services — every bot's — the grant may reach on the server: the active ones that were there when it was
     * issued, the agents' only for one that says so. Whether one is running or still waiting for its first connection is
     * the panel's to say, service by service (share()): the shop's copy is as old as the last time anyone asked.
     *
     * @return Builder<Subscription>
     */
    private static function candidates(Grant $grant, int $serverId): Builder
    {
        return self::forAudience(Subscription::active(Subscription::acrossShops()), $grant->audience)
            ->where('server_id', $serverId)
            ->where('id', '<=', $grant->upto_subscription_id);
    }

    /**
     * Active services counted in one grouped read, by server and bot: how many run, and how many still wait for their
     * first connection.
     *
     * @param Builder<Subscription> $services Active ones
     * @return list<array{server: int, bot: int, reach: array{running: int, unstarted: int}}>
     */
    private static function tally(Builder $services): array
    {
        $rows = $services->toBase()
            ->select(['server_id', 'bot_id'])
            ->selectRaw('COUNT(*) AS active')
            ->selectRaw(Subscription::runningCount() . ' AS running')
            ->groupBy('server_id', 'bot_id')
            ->get();

        return $rows->map(static fn(object $row): array => [
            'server' => (int) $row->server_id,
            'bot' => (int) $row->bot_id,
            'reach' => ['running' => (int) $row->running, 'unstarted' => (int) $row->active - (int) $row->running],
        ])->values()->all();
    }

    /**
     * Two reaches as one.
     *
     * @param array{running: int, unstarted: int} $one
     * @param array{running: int, unstarted: int} $other
     * @return array{running: int, unstarted: int}
     */
    private static function together(array $one, array $other): array
    {
        return ['running' => $one['running'] + $other['running'], 'unstarted' => $one['unstarted'] + $other['unstarted']];
    }

    /**
     * The services an audience is: the agents' — what the bots of agents whose agency stands sold (Bot::activeAgents(): a
     * bot whose agency ended is off, its customers no agents' to reach) — or anyone's.
     *
     * @param Builder<Subscription> $services
     * @return Builder<Subscription>
     */
    private static function forAudience(Builder $services, GrantAudience $audience): Builder
    {
        return $audience === GrantAudience::Agents ? $services->whereIn($services->qualifyColumn('bot_id'), Bot::activeAgents()->select('id')) : $services;
    }

    /** The panel is out of reach: the part waits at this service, saying why, and this call stops. */
    private function wait(GrantPart $part, Lease $lease, string $reason): bool
    {
        if ($part->waiting_reason === null) {
            $this->logger->warning('Grant {id} waits for the panel of server {server}: {reason}', ['id' => $part->grant_id, 'server' => $part->server_id, 'reason' => $reason]);
        }
        $lease->write(['waiting_reason' => mb_substr($reason, 0, self::TEXT_MAX)]);

        return false;
    }

    /** The part has been through every service on its server: done. */
    private function finish(GrantPart $part, Lease $lease): bool
    {
        $done = $lease->finish(['status' => GrantStatus::Done, 'finished_at' => now(), 'waiting_reason' => null]);
        if ($done) {
            $part->refresh();
            $this->logger->info('Grant {id} finished on server {server}: {granted} given, {skipped} passed by, {failed} failed', [
                'id' => $part->grant_id,
                'server' => $part->server_id,
                'granted' => $part->granted,
                'skipped' => $part->skipped,
                'failed' => $part->failed,
            ]);
        }

        return $done;
    }

    /**
     * What a stop from outside writes: the part ended, let go — whoever held it stops at its next step.
     *
     * @return array<string, mixed>
     */
    private static function stopped(): array
    {
        return ['status' => GrantStatus::Cancelled, 'finished_at' => now(), 'waiting_reason' => null] + Lease::FREE;
    }

    /** A service the panel refused, for the admin: its name on the panel and why — logged too. */
    private function failure(GrantPart $part, Subscription $subscription, ProviderException $e): string
    {
        $this->logger->warning('Grant {id} could not give to subscription {subscription}: {message}', ['id' => $part->grant_id, 'subscription' => $subscription->id, 'message' => $e->getMessage()]);

        return mb_substr("سرویس {$subscription->remote_name}: " . ProviderErrorPresenter::describe($e), 0, self::TEXT_MAX);
    }

    /**
     * What a grant gives, as the admin typed it — {days, traffic_gb, reason?, notify?, include_unstarted?} — checked.
     *
     * @param array<string, mixed> $input
     * @return array{days: int, traffic_bytes: int, reason: string|null, notify: bool, include_unstarted: bool}
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $gift = Gift::fromInput($input, 'reason');

        return [
            'days' => $gift->days,
            'traffic_bytes' => $gift->bytes,
            'reason' => $gift->note,
            'notify' => $gift->notify,
            'include_unstarted' => Input::truthy($input['include_unstarted'] ?? false),
        ];
    }
}
