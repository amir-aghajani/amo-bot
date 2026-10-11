<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Lease;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Bots\CurrentBot;
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
use App\Modules\Users\Models\CustomerGroup;
use App\Support\Input;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Days and traffic the owner gives running services — «افزودن زمان و حجم» on a server's page, «هدیه همگانی» on the
 * broadcasts page — with a reason the customers read.
 *
 * Who gets it: the active services that existed when it was issued (every bot's), narrowed by its audience (every
 * server, the agents' bots, one customer group, one server), and running when their turn comes by the panel's word
 * (share()). Services still waiting for their first connection only when the owner ticks `include_unstarted`; ended or
 * switched-off services never.
 *
 * How it runs: one part per server it reaches, each worked through one service at a time past a cursor by whoever holds
 * the part's lease — the open screen, or Tasks\GrantsTask every minute — so it finishes however many services there are,
 * and an unreachable panel holds up only its own server (`waiting_reason`). Each service is claimed before its panel is
 * touched, so none is given twice. The database allows one running part per server; cancelling stops every part still
 * going, and the services reached keep what they got.
 */
final class Grants
{
    /** How many grants a card lists. */
    private const HISTORY = 10;
    /** The reach of a grant that reaches no service. */
    private const NOBODY = ['running' => 0, 'unstarted' => 0];
    /** What the text columns hold. */
    private const TEXT_MAX = 500;
    /** The seconds one request of an open screen works on a grant; the screen asks again while it stays open. */
    private const SCREEN_SECONDS = 3;
    /** A cancel that found nothing running: the grant is done, or was stopped already. */
    private const NOT_RUNNING = 'این مورد دیگر در حال انجام نیست.';

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Issue a grant — {days, traffic_gb, reason?, notify?, include_unstarted?, audience: all|agents|server|group,
     * server_id?, group_id?} — with a part on every server where a service could take it. A group is one of the main
     * shop's (the mass gift is the owner's).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException on the amounts, the reason, `audience`, `server_id`, `group_id`, or on `grant`: a server
     *                             it would reach already has a grant under way, or no service could take it
     */
    public function start(array $input, Actor $actor): Grant
    {
        $values = $this->validate($input);
        $audience = GrantAudience::tryFrom(Input::text($input, 'audience')) ?? throw ValidationException::on('audience', 'به چه سرویس‌هایی برسد را انتخاب کنید.');
        $server = null;
        if ($audience === GrantAudience::Server) {
            $server = Server::query()->find(Input::integer($input, 'server_id') ?? 0) ?? throw ValidationException::on('server_id', 'سرور را انتخاب کنید.');
        }
        $group = null;
        if ($audience === GrantAudience::Group) {
            $id = Input::integer($input, 'group_id') ?? 0;
            $group = CurrentBot::run(CurrentBot::main(), static fn(): ?CustomerGroup => CustomerGroup::query()->find($id)) ?? throw ValidationException::on('group_id', 'گروه مشتریان را انتخاب کنید.');
        }
        $serverIds = $server !== null
            ? [$server->id]
            : self::forAudience(Subscription::active(Subscription::acrossShops()), $audience, $group?->id)->distinct()->orderBy('server_id')->pluck('server_id')->map(intval(...))->all();

        try {
            return $this->db->transaction(function () use ($values, $audience, $server, $group, $serverIds, $actor): Grant {
                $grant = Grant::query()->create($values + [
                    'audience' => $audience,
                    'customer_group_id' => $group?->id,
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
                    throw ValidationException::on('grant', match (true) {
                        $server !== null => 'روی این سرور سرویس فعالی نیست که این زمان یا حجم به آن برسد.',
                        $group !== null => 'مشتری‌های این گروه سرویس فعالی ندارند که این زمان یا حجم به آن برسد.',
                        default => 'سرویس فعالی نیست که این زمان یا حجم به آن برسد.',
                    });
                }

                $this->logger->info('Grant {id} issued: {days} days and {bytes} bytes on {servers} servers', ['id' => $grant->id, 'days' => $grant->days, 'bytes' => $grant->traffic_bytes, 'servers' => $grant->parts()->count()]);

                return $grant;
            });
        } catch (UniqueConstraintViolationException) {
            // A part already runs on one of its servers: the database keeps one per server.
            $busy = GrantPart::running()->whereIn('server_id', $serverIds)->with('server')->first();

            throw ValidationException::on('grant', sprintf('روی سرور «%s» افزودن زمان و حجم دیگری در حال انجام است؛ صبر کنید تمام شود یا آن را متوقف کنید.', $busy->server->name ?? ''));
        }
    }

    /**
     * Issue a grant to one server's services (its page) — {days, traffic_gb, reason?, notify?, include_unstarted?} — and
     * answer its one part, with its grant.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException as start()
     */
    public function startOn(Server $server, array $input, Actor $actor): GrantPart
    {
        $grant = $this->start(['audience' => GrantAudience::Server->value, 'server_id' => $server->id] + $input, $actor);

        return $grant->parts()->where('server_id', $server->id)->sole()->setRelation('grant', $grant);
    }

    /** An open screen's turn on the grant: SCREEN_SECONDS of work on its running parts, one server after another. */
    public function run(Grant $grant): void
    {
        $parts = $grant->parts()->where('status', GrantStatus::Running->value)->with('server')->get();
        $this->work($parts->each(static fn(GrantPart $part) => $part->setRelation('grant', $grant)), microtime(true) + self::SCREEN_SECONDS);
    }

    /**
     * An open screen's turn on one part (its server's page): SCREEN_SECONDS of work, unless it stopped or someone else
     * holds it.
     */
    public function runPart(GrantPart $part): void
    {
        if ($part->isRunning()) {
            $this->process($part, microtime(true) + self::SCREEN_SECONDS);
        }
    }

    /** Work on every running part, oldest first, until `$until` — the scheduler's turn, for parts no screen is working. */
    public function runAll(float $until): void
    {
        $this->work(GrantPart::running()->with(['grant', 'server'])->oldest('id')->get(), $until);
    }

    /**
     * Work on the part until it is done or `$until` passes, unless someone else holds it: one service at a time, each
     * claimed before its panel is touched. The lease lasts as long as a service's hold (ProvisioningService::holdSeconds())
     * and every step renews it. True when this call finished the part.
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

    /**
     * Stop every running part of the grant; the services reached keep what they got.
     *
     * @throws ValidationException 422 on `status`: no part of it was still running
     */
    public function cancel(Grant $grant): void
    {
        if (GrantPart::running()->where('grant_id', $grant->id)->update(self::stopped()) === 0) {
            throw ValidationException::on('status', self::NOT_RUNNING);
        }
        $this->logger->info('Grant {id} cancelled', ['id' => $grant->id]);
    }

    /**
     * Stop the grant on one server (from its page); its other servers go on.
     *
     * @throws ValidationException 422 on `status`: it was no longer running
     */
    public function cancelPart(GrantPart $part): void
    {
        if (GrantPart::running()->whereKey($part->id)->update(self::stopped()) !== 1) {
            throw ValidationException::on('status', self::NOT_RUNNING);
        }
        $this->logger->info('Grant {id} cancelled on server {server}', ['id' => $part->grant_id, 'server' => $part->server_id]);
    }

    /**
     * Whom a new mass grant would reach, by the shop's last copy of its services: everyone, the agents, each server with
     * services, and each of the main shop's customer groups in the admin's order — two grouped reads in all.
     *
     * @return array{all: array{running: int, unstarted: int}, agents: array{running: int, unstarted: int}, servers: list<array{id: int, name: string, running: int, unstarted: int}>, groups: list<array{id: int, name: string, running: int, unstarted: int}>}
     */
    public function audience(): array
    {
        $all = $agents = self::NOBODY;
        $servers = [];
        $agentBots = Bot::activeAgents()->pluck('id')->map(intval(...))->all();
        foreach (self::tally(Subscription::active(Subscription::acrossShops())) as ['server' => $server, 'bot' => $bot, 'reach' => $reach]) {
            $all = self::together($all, $reach);
            $agents = in_array($bot, $agentBots, true) ? self::together($agents, $reach) : $agents;
            $servers[$server] = self::together($servers[$server] ?? self::NOBODY, $reach);
        }
        $names = Server::query()->whereIn('id', array_keys($servers))->oldest('id')->pluck('name', 'id');

        return [
            'all' => $all,
            'agents' => $agents,
            'servers' => $names->map(static fn(string $name, int $id): array => ['id' => $id, 'name' => $name] + $servers[$id])->values()->all(),
            'groups' => self::groupReach(),
        ];
    }

    /**
     * Whom a grant on the server would reach, by the shop's last copy of its services (every bot's): those running and
     * those still waiting for their first connection. The panel decides when the grant runs: a customer may have
     * connected since.
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

    /** @return list<array<string, mixed>> The latest grants, newest first (the broadcasts page). */
    public function history(): array
    {
        return Grant::query()->with(['parts.server', 'customerGroup'])->latest('id')->limit(self::HISTORY)->get()->map($this->present(...))->values()->all();
    }

    /** @return list<array<string, mixed>> The latest grants' parts on the server, newest first (its page). */
    public function historyOn(Server $server): array
    {
        return GrantPart::query()->where('server_id', $server->id)->with('grant')->latest('id')->limit(self::HISTORY)->get()->map($this->presentPart(...))->values()->all();
    }

    /**
     * A grant with its parts' numbers added up, and each part. Its parts and their servers, and its group, loaded.
     *
     * @return array<string, mixed>
     */
    public function present(Grant $grant): array
    {
        $parts = $grant->parts;
        $sum = static fn(string $tally): int => (int) $parts->sum($tally);
        $server = $grant->audience === GrantAudience::Server ? $parts->first()?->server : null;
        $group = $grant->audience === GrantAudience::Group ? $grant->customerGroup : null;

        return ['id' => $grant->id] + self::terms($grant) + [
            'audience' => $grant->audience->value,
            'server' => $server !== null ? ['id' => $server->id, 'name' => $server->name] : null,
            'group' => $group !== null ? ['id' => $group->id, 'name' => $group->name] : null,
            'status' => $grant->status()->value,
            'total' => $sum('total'),
            'granted' => $sum('granted'),
            'skipped' => $sum('skipped'),
            'failed' => $sum('failed'),
            'parts' => $parts->map(static fn(GrantPart $part): array => [
                'id' => $part->id,
                'server' => ['id' => $part->server_id, 'name' => $part->server->name ?? ''],
            ] + self::progress($part))->values()->all(),
            'reviewer' => $grant->reviewer,
            'created_at' => $grant->created_at->toIso8601String(),
            'finished_at' => $grant->finishedAt()?->toIso8601String(),
        ];
    }

    /**
     * A part as its server's page shows it: the grant's terms and how far it got there. `mass_grant_id` is set when the
     * grant reaches beyond this server (it is on the broadcasts page too).
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
        ] + self::terms($grant) + self::progress($part) + [
            'reviewer' => $grant->reviewer,
            'created_at' => $grant->created_at->toIso8601String(),
            'finished_at' => $part->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Work on each part in turn until `$until`.
     *
     * @param iterable<GrantPart> $parts
     */
    private function work(iterable $parts, float $until): void
    {
        foreach ($parts as $part) {
            if (microtime(true) >= $until) {
                return;
            }
            $this->process($part, $until);
        }
    }

    /**
     * The next service's turn: its share given (and the customer told, if the grant says so), or passed by. False when
     * this call must stop: the panel is out of reach or the service busy (the part waits at this service), or the part
     * is no longer this call's (stopped, or taken over after its lease ran out).
     */
    private function give(GrantPart $part, Subscription $subscription, Lease $lease): bool
    {
        $server = $subscription->server;
        if ($server->isBackingOff()) {
            return $this->wait($part, $lease, (string) $server->last_error);
        }
        // Renewed before the read: a worker at it keeps the part.
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
            // counted, not retried: the service must not be given twice.
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
     * What the service takes of the grant, by the panel's word just read: the days when it has a term, the traffic when
     * it has a quota. Null when the grant is not for it: not running (ended, or switched off on the panel itself), still
     * waiting for its first connection without the owner's tick, or able to take neither.
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
     * The services (every bot's) the grant may reach on the server: active, existing when it was issued, in its audience.
     * Whether each one is running is the panel's to say when its turn comes (share()).
     *
     * @return Builder<Subscription>
     */
    private static function candidates(Grant $grant, int $serverId): Builder
    {
        return self::forAudience(Subscription::active(Subscription::acrossShops()), $grant->audience, $grant->customer_group_id)
            ->where('server_id', $serverId)
            ->where('id', '<=', $grant->upto_subscription_id);
    }

    /**
     * Each of the main shop's customer groups, in the admin's order, with whom a grant to it would reach: its customers'
     * active services counted in one grouped read.
     *
     * @return list<array{id: int, name: string, running: int, unstarted: int}>
     */
    private static function groupReach(): array
    {
        $groups = CurrentBot::run(CurrentBot::main(), static fn() => CustomerGroup::query()->orderBy('sort')->orderBy('id')->get(['id', 'name']));
        if ($groups->isEmpty()) {
            return [];
        }

        $rows = Subscription::active(Subscription::acrossShops())->toBase()
            ->join('customer_group_user', 'customer_group_user.user_id', '=', 'subscriptions.user_id')
            ->whereIn('customer_group_user.customer_group_id', $groups->modelKeys())
            ->select('customer_group_user.customer_group_id')
            ->selectRaw('COUNT(*) AS active')
            ->selectRaw(Subscription::runningCount() . ' AS running')
            ->groupBy('customer_group_user.customer_group_id')
            ->get()
            ->keyBy(static fn(object $row): int => (int) $row->customer_group_id);

        return $groups->map(static function (CustomerGroup $group) use ($rows): array {
            $row = $rows->get($group->id);

            return [
                'id' => $group->id,
                'name' => $group->name,
                'running' => (int) ($row->running ?? 0),
                'unstarted' => (int) ($row->active ?? 0) - (int) ($row->running ?? 0),
            ];
        })->values()->all();
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
     * Two reaches added together.
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
     * The services in an audience: the agents' (sold by bots whose agent's agency stands — an ended agency's bot is
     * off), a customer group's customers' (as the group is now; a group deleted since — `$group` null — reaches nobody,
     * never everyone), or anyone's.
     *
     * @param Builder<Subscription> $services
     * @return Builder<Subscription>
     */
    private static function forAudience(Builder $services, GrantAudience $audience, ?int $group): Builder
    {
        return match ($audience) {
            GrantAudience::Agents => $services->whereIn($services->qualifyColumn('bot_id'), Bot::activeAgents()->select('id')),
            GrantAudience::Group => $group === null
                ? $services->whereRaw('1 = 0')
                : $services->whereIn($services->qualifyColumn('user_id'), static fn(QueryBuilder $members) => $members->select('user_id')->from('customer_group_user')->where('customer_group_id', $group)),
            default => $services,
        };
    }

    /** The panel is out of reach: the part waits at this service with the reason, and this call stops. */
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
     * What a stop from outside writes: the part ended and its lease freed — whoever held it stops at its next step.
     *
     * @return array<string, mixed>
     */
    private static function stopped(): array
    {
        return ['status' => GrantStatus::Cancelled, 'finished_at' => now(), 'waiting_reason' => null] + Lease::FREE;
    }

    /** A service the panel refused, for the owner: its name on the panel and why — logged too. */
    private function failure(GrantPart $part, Subscription $subscription, ProviderException $e): string
    {
        $this->logger->warning('Grant {id} could not give to subscription {subscription}: {message}', ['id' => $part->grant_id, 'subscription' => $subscription->id, 'message' => $e->getMessage()]);

        return mb_substr("سرویس {$subscription->remote_name}: " . ProviderErrorPresenter::describe($e), 0, self::TEXT_MAX);
    }

    /**
     * What a grant gives, as the screens present it.
     *
     * @return array{days: int, traffic_bytes: int, reason: string|null, notify: bool, include_unstarted: bool}
     */
    private static function terms(Grant $grant): array
    {
        return [
            'days' => $grant->days,
            'traffic_bytes' => $grant->traffic_bytes,
            'reason' => $grant->reason,
            'notify' => $grant->notify,
            'include_unstarted' => $grant->include_unstarted,
        ];
    }

    /**
     * How far a part got, as the screens present it.
     *
     * @return array{status: string, total: int, granted: int, skipped: int, failed: int, waiting_reason: string|null, last_failure: string|null}
     */
    private static function progress(GrantPart $part): array
    {
        return [
            'status' => $part->status->value,
            'total' => $part->total,
            'granted' => $part->granted,
            'skipped' => $part->skipped,
            'failed' => $part->failed,
            'waiting_reason' => $part->waiting_reason,
            'last_failure' => $part->last_failure,
        ];
    }

    /**
     * What a grant gives, as the owner typed it — {days, traffic_gb, reason?, notify?, include_unstarted?} — checked.
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
