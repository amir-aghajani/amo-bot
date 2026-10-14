<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Lease;
use App\Core\Exceptions\ValidationException;
use App\Core\Scheduling\Budget;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Services\SessionPresenter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Catalog\Exceptions\NoServerAvailableException;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Providers\Services\ServerReadiness;
use App\Modules\Subscriptions\Enums\MigrationMode;
use App\Modules\Subscriptions\Enums\MigrationStatus;
use App\Modules\Subscriptions\Enums\MoveStep;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\MoveException;
use App\Modules\Subscriptions\Exceptions\ServiceBusyException;
use App\Modules\Subscriptions\Models\MigrationFailure;
use App\Modules\Subscriptions\Models\MigrationLeftover;
use App\Modules\Subscriptions\Models\ServerMigration;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Api\Pacer;
use App\Modules\Telegram\Reports\ShopReports;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Text;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * «انتقال سرویس‌ها» on a server's page: every shop's services on one server moved to another in bulk, each with
 * ProvisioningService::move() — «مهاجرت» (migrate) while the source's panel answers: each client read there, made on
 * the target with what it has left, deleted there; «نجات» (rescue) when it does not: made on the target from the shop's
 * own last copy, the source never asked, and the old client written down (a leftover) to be removed once that panel
 * answers again — by the scheduler, or the owner's «حذف همین حالا». A client a panel did not say it deleted or made is
 * written down so in either mode, as a single move's is (move()). Each customer is sent the new link in their own
 * shop's bot, as a single move does, at the bulk pace (Pacer); the report group hears when a job starts and ends. The
 * owner's job moves an agent's services onto a server kept from that agent's shop too: that list binds single moves
 * (SubscriptionActions).
 *
 * Who moves: the services on the source when the job was started (`upto_subscription_id`) — the active ones, those
 * support switched off when the owner ticks it (moved switched off), only some plans' when asked — still there and with
 * something left when their turn comes. Ended services stay: they have nothing to carry.
 *
 * How it runs: as a grant's part (Grants) — one service at a time past a cursor, by whoever holds the job's lease: the
 * open page a few seconds a request (run()), or Tasks\MigrationsTask every minute (runAll()). Each service is claimed
 * before any panel is touched, so none is moved twice. A panel out of reach holds the job at that service
 * (`waiting_reason`) and it goes on once the panel answers; a service another change holds is come back to on the next
 * turn; a target that can take no more (full, switched off, no links) ends it as failed, with the reason. Pause, resume,
 * cancel and the switch to rescue are compare-and-swaps on the status and mode the job's lease is held in, so whoever
 * holds it stops at its next step: a cancel frees the lease with it; a pause or the switch leaves it to its holder, which
 * still puts its cursor back on a service it must come back to, then lets it go. The database allows one open job per
 * source server.
 */
final class ServerMigrations
{
    /** How many jobs a server's card lists. */
    private const HISTORY = 10;
    /** How many of a job's failures its details show, the latest first. */
    private const FAILURES = 200;
    /** What the text columns hold. */
    private const TEXT_MAX = 500;
    /** How many of a server's leftovers one turn reads to remove; the rest wait for the next. */
    private const CLEAN_BATCH = 500;
    /** The most plans a job is narrowed to (the contract's `maxItems`): every shop's plans on one server, far fewer. */
    private const PLANS_MAX = 1000;
    /** A pause, cancel or switch that found the job over. */
    private const NOT_OPEN = 'این انتقال دیگر در جریان نیست.';

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly ServerReadiness $readiness,
        private readonly CustomerNotifier $notifier,
        private readonly ShopReports $reports,
        private readonly SessionPresenter $shops,
        private readonly Pacer $pacer,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * What a job from the server would move — {target_server_id?, include_disabled?, plan_ids?} —, by the shop's last
     * copy: how many services, of which shops; the plans the services there are of (the filter's choices, counted without
     * it) and how many have none; and, with a target, why it cannot take them now (null when it can) and its room (null:
     * no capacity set).
     *
     * @param array<string, mixed> $input
     * @return array{services: int, shops: list<array{shop: array<string, mixed>, services: int}>, plans: list<array{id: int, name: string, shop: string, services: int}>, unplanned: int, target: array{problem: string|null, room: int|null}|null}
     * @throws ValidationException on `include_disabled`, `plan_ids`
     */
    public function preview(Server $source, array $input): array
    {
        $switchedOff = self::switchOf($input, 'include_disabled', false);
        $byShop = self::services($source->id, $switchedOff, self::plansOf($input))->toBase()
            ->select('bot_id')->selectRaw('COUNT(*) AS services')->groupBy('bot_id')->pluck('services', 'bot_id');
        $byPlan = self::services($source->id, $switchedOff, null)->toBase()
            ->select('plan_id')->selectRaw('COUNT(*) AS services')->groupBy('plan_id')->pluck('services', 'plan_id');

        $plans = Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereIn('id', $byPlan->keys()->filter())->orderBy('bot_id')->orderBy('sort')->orderBy('id')->get();
        $bots = Bot::query()->with('agent')->whereIn('id', $byShop->keys()->merge($plans->pluck('bot_id'))->unique())->orderBy('id')->get()->keyBy('id');
        $target = Server::query()->find(Input::integer($input, 'target_server_id') ?? 0);

        return [
            'services' => (int) $byShop->sum(),
            'shops' => $bots->filter(static fn(Bot $bot): bool => isset($byShop[$bot->id]))
                ->map(fn(Bot $bot): array => ['shop' => $this->shops->shop($bot), 'services' => (int) $byShop[$bot->id]])->values()->all(),
            'plans' => $plans->map(fn(Plan $plan): array => [
                'id' => $plan->id,
                'name' => $plan->name,
                'shop' => $this->shops->shop($bots[$plan->bot_id])['name'],
                'services' => (int) $byPlan[$plan->id],
            ])->values()->all(),
            'unplanned' => (int) $byPlan->filter(static fn(mixed $services, mixed $plan): bool => $plan === '' || $plan === null)->sum(),
            'target' => $target === null ? null : [
                'problem' => $target->id === $source->id ? 'همین سرور است.' : $this->readiness->problem($target),
                'room' => $target->capacity === null ? null : max(0, $target->capacity - $target->activeServices()),
            ],
        ];
    }

    /**
     * Start moving the server's services — {target_server_id, mode: migrate|rescue, include_disabled?, notify?,
     * plan_ids?} —, queued for its first turn; the report group hears it.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException on `target_server_id` (none, this server, one that cannot take services now, one being
     *                             emptied itself), `mode`, `plan_ids`, `include_disabled`, `notify`, or on `migration`:
     *                             another job from this server is open, services are being moved onto it, or there is
     *                             nothing to move
     */
    public function start(Server $source, array $input, Actor $actor): ServerMigration
    {
        $mode = MigrationMode::tryFrom(Input::text($input, 'mode')) ?? throw ValidationException::on('mode', 'حالت انتقال را انتخاب کنید.');
        $target = $this->target($source, $input);
        $plans = self::plansOf($input);
        $switchedOff = self::switchOf($input, 'include_disabled', false);
        $notify = self::switchOf($input, 'notify', true);
        $incoming = ServerMigration::open()->where('target_server_id', $source->id)->with('source')->first();
        if ($incoming !== null) {
            throw ValidationException::on('migration', sprintf('سرویس‌های سرور «%s» در حال انتقال به همین سرور است؛ صبر کنید تمام شود یا آن را لغو کنید.', $incoming->source->name ?? ''));
        }

        try {
            return $this->db->transaction(function () use ($source, $target, $mode, $plans, $switchedOff, $notify, $actor): ServerMigration {
                $upto = (int) Subscription::acrossShops()->max('id');
                $total = self::services($source->id, $switchedOff, $plans)->where('id', '<=', $upto)->count();
                if ($total === 0) {
                    throw ValidationException::on('migration', $plans === null && !$switchedOff ? 'روی این سرور سرویس فعالی برای انتقال نیست.' : 'روی این سرور سرویسی با این انتخاب برای انتقال نیست.');
                }

                $job = ServerMigration::query()->create([
                    'source_server_id' => $source->id,
                    'target_server_id' => $target->id,
                    'mode' => $mode,
                    'include_disabled' => $switchedOff,
                    'plan_ids' => $plans,
                    'notify' => $notify,
                    'status' => MigrationStatus::Queued,
                    'upto_subscription_id' => $upto,
                    'total' => $total,
                    'reviewer' => $actor->reviewer,
                ]);
                $job->setRelation('source', $source)->setRelation('target', $target);
                $this->reports->migrationStarted($job);
                $this->logger->info('Migration {id} started: {total} services from {from} to {to} ({mode})', ['id' => $job->id, 'total' => $total, 'from' => $source->name, 'to' => $target->name, 'mode' => $mode->value]);

                return $job->refresh()->setRelation('source', $source)->setRelation('target', $target);
            });
        } catch (UniqueConstraintViolationException) {
            // A job from this server is open already: the database keeps one per source.
            throw ValidationException::on('migration', 'انتقال دیگری از این سرور در جریان است؛ صبر کنید تمام شود یا آن را لغو کنید.');
        }
    }

    /** An open page's turn on the job (Budget::screen()), unless it is paused or over, or someone else holds it. */
    public function run(ServerMigration $job): void
    {
        if (in_array($job->status, MigrationStatus::workable(), true)) {
            $this->process($job, Budget::screen());
        }
    }

    /**
     * The scheduler's turn until `$until`: every job a turn works on, oldest first; then the clients moves left on
     * panels that answer again (not in backoff), removed.
     */
    public function runAll(float $until): void
    {
        foreach (ServerMigration::workable()->with(['source', 'target'])->oldest('id')->get() as $job) {
            if (microtime(true) >= $until) {
                return;
            }
            $this->process($job, $until);
        }

        foreach (Server::query()->whereIn('id', MigrationLeftover::pending()->select('server_id'))->oldest('id')->get() as $server) {
            if (microtime(true) >= $until) {
                return;
            }
            if (!$server->isBackingOff()) {
                $this->clean($server, $until, evenInBackoff: false);
            }
        }
    }

    /**
     * Work on the job until it is through or `$until` passes, unless someone else holds it: one service at a time, each
     * claimed before any panel is touched. The lease lasts as long as a service's hold on either server
     * (ProvisioningService::holdSeconds()) and every step renews it; it holds while the job is workable in the mode it was
     * taken in, so a pause or the switch to rescue stops this call at its next step. True when this call finished the job.
     */
    public function process(ServerMigration $job, float $until): bool
    {
        $job->loadMissing(['source', 'target']);
        if ($job->source === null || $job->target === null) {
            throw new \LogicException("Migration #{$job->id} has lost a server.");
        }
        $mode = $job->mode;
        $lease = Lease::take($job, ProvisioningService::holdSeconds($job->source, $job->target), static fn(Builder $q) => $q->whereIn('status', MigrationStatus::values(MigrationStatus::workable()))->where('mode', $mode->value));

        return $lease !== null && $lease->work(
            $until,
            start: fn(): bool => $job->status !== MigrationStatus::Queued || $lease->write(['status' => MigrationStatus::Running, 'started_at' => $job->started_at ?? now()]),
            next: fn(): ?Subscription => self::candidates($job)->where('id', '>', $job->last_subscription_id)->with('server')->oldest('id')->first(),
            step: fn(Subscription $subscription): bool => $this->carry($job, $subscription, $lease),
            finish: fn(): bool => $this->finish($job, $lease),
        );
    }

    /**
     * Pause the job where it is; resume() goes on from there. Its lease stays its holder's: a service under way that must
     * be come back to (a panel out of reach) is put back under the cursor, not passed by.
     *
     * @throws ValidationException 422 on `status`: it was not queued or running
     */
    public function pause(ServerMigration $job): void
    {
        if (ServerMigration::workable()->whereKey($job->id)->update(['status' => MigrationStatus::Paused, 'waiting_reason' => null]) !== 1) {
            throw ValidationException::on('status', 'این انتقال در جریان نیست که متوقف شود.');
        }
        $this->logger->info('Migration {id} paused', ['id' => $job->id]);
    }

    /**
     * Go on with a paused job from where it stopped.
     *
     * @throws ValidationException 422 on `status`: it was not paused
     */
    public function resume(ServerMigration $job): void
    {
        $status = $job->started_at === null ? MigrationStatus::Queued : MigrationStatus::Running;
        if (ServerMigration::query()->whereKey($job->id)->where('status', MigrationStatus::Paused->value)->update(['status' => $status]) !== 1) {
            throw ValidationException::on('status', 'این انتقال متوقف نیست که ادامه پیدا کند.');
        }
        $this->logger->info('Migration {id} resumed', ['id' => $job->id]);
    }

    /**
     * End the job; the services moved stay moved, the rest stay where they are. The report group hears it.
     *
     * @throws ValidationException 422 on `status`: it was over already
     */
    public function cancel(ServerMigration $job): void
    {
        if (ServerMigration::open()->whereKey($job->id)->update(['status' => MigrationStatus::Cancelled, 'finished_at' => now(), 'waiting_reason' => null] + Lease::FREE) !== 1) {
            throw ValidationException::on('status', self::NOT_OPEN);
        }
        $this->logger->info('Migration {id} cancelled', ['id' => $job->id]);
        $this->reports->migrationEnded($job->refresh()->load(['source', 'target']));
    }

    /**
     * «ادامه با حالت نجات»: a migration waiting for a source that does not answer goes on as a rescue — the source no
     * longer asked, its clients left there and written down for removal. As a pause does, it leaves the lease to its
     * holder, which stops at its next step: the next turn takes the job as a rescue.
     *
     * @throws ValidationException 422 on `status`: it is a rescue already, or over
     */
    public function toRescue(ServerMigration $job): void
    {
        $switched = ServerMigration::open()->whereKey($job->id)->where('mode', MigrationMode::Migrate->value)
            ->update(['mode' => MigrationMode::Rescue, 'waiting_reason' => null]);
        if ($switched !== 1) {
            throw ValidationException::on('status', $job->mode === MigrationMode::Rescue ? 'این انتقال همین حالا در حالت نجات است.' : self::NOT_OPEN);
        }
        $this->logger->info('Migration {id} goes on as a rescue', ['id' => $job->id]);
    }

    /**
     * The clients moves left on the server's panel, removed for a request's turn (Budget::screen()) — the owner's «حذف
     * همین حالا», which asks the panel even while it is in backoff (once: a failure stops it).
     */
    public function cleanNow(Server $server): void
    {
        $this->clean($server, Budget::screen(), evenInBackoff: true);
    }

    /** Forget the clients moves left on the server's panel (it is gone for good): none is removed. Returns how many. */
    public function forgetLeftovers(Server $server): int
    {
        $forgotten = MigrationLeftover::pending()->where('server_id', $server->id)->delete();
        $this->logger->info('{count} clients left on {server} by rescues forgotten', ['count' => $forgotten, 'server' => $server->name]);

        return $forgotten;
    }

    /** @return list<array<string, mixed>> The latest jobs from the server, newest first (its page). */
    public function historyOn(Server $source): array
    {
        $jobs = ServerMigration::query()->where('source_server_id', $source->id)->with(['source', 'target'])->withCount('leftovers')
            ->latest('id')->limit(self::HISTORY)->get();
        $plans = self::planNames($jobs);

        return $jobs->map(fn(ServerMigration $job): array => $this->present($job, $plans))->values()->all();
    }

    /**
     * The clients moves left on the server's panel still to remove, and why the last try failed.
     *
     * @return array{pending: int, last_error: string|null}
     */
    public function leftoversOn(Server $server): array
    {
        $pending = MigrationLeftover::pending()->where('server_id', $server->id);

        return [
            'pending' => (clone $pending)->count(),
            'last_error' => (clone $pending)->whereNotNull('last_error')->latest('id')->value('last_error'),
        ];
    }

    /**
     * A job as the screens show it: where from and to, how, how far it got, what holds it up or ended it.
     *
     * @param array<int, string>|null $plans The names of the plans jobs are narrowed to, by id: read with the list
     *                                       (historyOn()), else now
     * @return array<string, mixed>
     */
    public function present(ServerMigration $job, ?array $plans = null): array
    {
        $plans ??= self::planNames([$job]);
        // Counted with the list (historyOn()), else now.
        $left = $job->getAttributes()['leftovers_count'] ?? null;

        return [
            'id' => $job->id,
            'source' => ['id' => $job->source_server_id, 'name' => $job->source->name ?? ''],
            'target' => ['id' => $job->target_server_id, 'name' => $job->target->name ?? ''],
            'mode' => $job->mode->value,
            'include_disabled' => $job->include_disabled,
            'notify' => $job->notify,
            'plans' => $job->plan_ids === null ? null : collect($job->plan_ids)->filter(static fn(int $id): bool => isset($plans[$id]))->sort()
                ->map(static fn(int $id): array => ['id' => $id, 'name' => $plans[$id]])->values()->all(),
            'status' => $job->status->value,
            'total' => $job->total,
            'moved' => $job->moved,
            'failed' => $job->failed,
            'skipped' => $job->skipped,
            'left_behind' => $left !== null ? (int) $left : $job->leftovers()->count(),
            'waiting_reason' => $job->waiting_reason,
            'reason' => $job->reason,
            'last_failure' => $job->last_failure,
            'reviewer' => $job->reviewer,
            'created_at' => $job->created_at->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
        ];
    }

    /**
     * The services the job could not move, the latest first (FAILURES of them): which, of which shop while it lasts, and
     * why.
     *
     * @return list<array{id: int, subscription_id: int|null, client: string, shop: array<string, mixed>|null, reason: string, created_at: string}>
     */
    public function failures(ServerMigration $job): array
    {
        return $job->failures()->with('subscription.bot.agent')->limit(self::FAILURES)->get()->map(fn(MigrationFailure $failure): array => [
            'id' => $failure->id,
            'subscription_id' => $failure->subscription?->id,
            'client' => $failure->remote_name,
            'shop' => $failure->subscription !== null ? $this->shops->shop($failure->subscription->shop()) : null,
            'reason' => $failure->reason,
            'created_at' => $failure->created_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * The next service's turn: moved (its customer told, if the job says so), passed by, or counted failed with why.
     * False when this call must stop: a panel is out of reach or the service busy (the job waits at this service), the
     * target can take no more (the job failed), or the job is no longer this call's (paused, cancelled, or taken over
     * after its lease ran out).
     */
    private function carry(ServerMigration $job, Subscription $subscription, Lease $lease): bool
    {
        $source = $job->source ?? throw new \LogicException("Migration #{$job->id} has lost its source.");
        $target = $job->target?->refresh() ?? throw new \LogicException("Migration #{$job->id} has lost its target.");
        $rescue = $job->mode === MigrationMode::Rescue;

        // The target as it stands now — one that can take no more ends the job —, and the panels out of reach: the
        // target's, and the source's for a migration (a rescue never asks it).
        $problem = $this->readiness->problem($target);
        if ($problem !== null) {
            return $this->fail($job, $lease, self::onServer('مقصد', $target, $problem));
        }
        if ($target->isBackingOff()) {
            return $this->wait($job, $lease, self::onServer('مقصد', $target, (string) $target->last_error));
        }
        if (!$rescue && $subscription->server->isBackingOff()) {
            return $this->wait($job, $lease, self::onServer('مبدا', $subscription->server, (string) $subscription->server->last_error));
        }

        // Claimed before any panel is touched: whatever happens next, it is never moved twice.
        $previous = $job->last_subscription_id;
        if (!$lease->write(['last_subscription_id' => $subscription->id, 'waiting_reason' => null])) {
            return false;
        }

        try {
            // A rescue's old clients — and in either mode a client a panel did not say it deleted or made — are written
            // down as the job's, to be removed once their panel answers (clean()).
            $this->provisioning->move($subscription, $target, leavePrevious: $rescue, switchedOff: $job->include_disabled, from: $source, by: $job);
        } catch (ServiceBusyException) {
            // Another change of the service is under way: back to it on the next turn.
            $lease->move('last_subscription_id', $subscription->id, $previous);

            return false;
        } catch (ModelNotFoundException) {
            // Deleted meanwhile.
            return $this->tally($job, 'skipped');
        } catch (NoServerAvailableException $e) {
            // The target as a whole changed since it was asked above (it ends the job), or what this service's plan may
            // have there (it alone fails).
            $problem = $this->readiness->problem($target->refresh());

            return $problem !== null ? $this->fail($job, $lease, self::onServer('مقصد', $target, $problem)) : $this->failed($job, $subscription, $e->getMessage());
        } catch (MoveException $e) {
            return $this->unmoved($job, $subscription, $lease, $previous, $e);
        }

        $this->tally($job, 'moved');
        if ($job->notify) {
            $this->notifier->serviceMoved($subscription);
            $this->pacer->pace();
        }

        return true;
    }

    /**
     * A move that did not happen: the service itself had nothing to carry, or was no longer there (passed by); a panel
     * that could not be worked with (ProviderException::unavailable()) holds the job at this service until it answers —
     * a move takes back what it made and writes down what it could not be sure of, so trying again is safe —; anything
     * else a panel refused is counted failed.
     */
    private function unmoved(ServerMigration $job, Subscription $subscription, Lease $lease, int $previous, MoveException $e): bool
    {
        if ($e->step === MoveStep::Service) {
            return $this->tally($job, 'skipped');
        }

        $words = $e->worded(static fn(ProviderException $panel): string => ProviderErrorPresenter::describe($panel))->getMessage();
        $panel = $e->getPrevious();
        if ($panel instanceof ProviderException && $panel->unavailable()) {
            $lease->move('last_subscription_id', $subscription->id, $previous);

            return $this->wait($job, $lease, $words);
        }

        return $this->failed($job, $subscription, $words);
    }

    /** One more under `$tally` (moved, skipped), by the job's key: the service was claimed, whoever holds the job now. */
    private function tally(ServerMigration $job, string $tally): bool
    {
        ServerMigration::query()->whereKey($job->id)->increment($tally);

        return true;
    }

    /** A service the job could not move, kept with why — logged too — and counted. */
    private function failed(ServerMigration $job, Subscription $subscription, string $reason): bool
    {
        $reason = Text::fit($reason, self::TEXT_MAX);
        $this->logger->warning('Migration {id} could not move subscription {subscription}: {reason}', ['id' => $job->id, 'subscription' => $subscription->id, 'reason' => $reason]);
        $this->db->transaction(static function () use ($job, $subscription, $reason): void {
            MigrationFailure::query()->create([
                'migration_id' => $job->id,
                'subscription_id' => $subscription->id,
                'remote_name' => $subscription->remote_name,
                'reason' => $reason,
            ]);
            ServerMigration::query()->whereKey($job->id)->increment('failed', 1, ['last_failure' => Text::fit("سرویس {$subscription->remote_name}: {$reason}", self::TEXT_MAX)]);
        });

        return true;
    }

    /** A panel is out of reach: the job waits at this service with the reason, and this call stops. */
    private function wait(ServerMigration $job, Lease $lease, string $reason): bool
    {
        if ($job->waiting_reason === null) {
            $this->logger->warning('Migration {id} waits: {reason}', ['id' => $job->id, 'reason' => $reason]);
        }
        $lease->write(['waiting_reason' => Text::fit($reason, self::TEXT_MAX)]);

        return false;
    }

    /** The target can take no more: the job ends as failed with the reason, and the report group hears it. */
    private function fail(ServerMigration $job, Lease $lease, string $reason): bool
    {
        if ($lease->finish(['status' => MigrationStatus::Failed, 'reason' => Text::fit($reason, self::TEXT_MAX), 'finished_at' => now(), 'waiting_reason' => null])) {
            $this->logger->warning('Migration {id} failed: {reason}', ['id' => $job->id, 'reason' => $reason]);
            $this->reports->migrationEnded($job->refresh());
        }

        return false;
    }

    /** The job has been through every service: done, and the report group hears it. */
    private function finish(ServerMigration $job, Lease $lease): bool
    {
        $done = $lease->finish(['status' => MigrationStatus::Done, 'finished_at' => now(), 'waiting_reason' => null]);
        if ($done) {
            $job->refresh();
            $this->logger->info('Migration {id} finished: {moved} moved, {skipped} passed by, {failed} failed', ['id' => $job->id, 'moved' => $job->moved, 'skipped' => $job->skipped, 'failed' => $job->failed]);
            $this->reports->migrationEnded($job);
        }

        return $done;
    }

    /**
     * Remove the clients moves left on the server's panel, oldest first, until `$until` — while the panel answers, or
     * even in backoff (`$evenInBackoff`, the owner's button: a panel out of reach stops it at its first contact).
     * ProvisioningService::removeLeftover() holds each one's service meanwhile: a client gone from the panel already
     * counts as removed; one by a name a service is by on that server again is not touched, only forgotten. A panel out
     * of reach stops it; a client the panel refused, or whose service is busy, is tried again next time.
     */
    private function clean(Server $server, float $until, bool $evenInBackoff): void
    {
        foreach (MigrationLeftover::pending()->where('server_id', $server->id)->with('subscription.server')->oldest('id')->limit(self::CLEAN_BATCH)->get() as $leftover) {
            if (microtime(true) >= $until || (!$evenInBackoff && $server->isBackingOff())) {
                return;
            }

            try {
                if (!$this->provisioning->removeLeftover($server, $leftover->remote_name, $leftover->subscription)) {
                    $leftover->delete();

                    continue;
                }
            } catch (ServiceBusyException) {
                continue;
            } catch (ProviderException $e) {
                MigrationLeftover::query()->whereKey($leftover->id)->update(['last_error' => Text::fit(ProviderErrorPresenter::describe($e), self::TEXT_MAX)]);
                if ($e->unavailable()) {
                    return;
                }

                continue;
            }
            MigrationLeftover::query()->whereKey($leftover->id)->whereNull('removed_at')->update(['removed_at' => now(), 'last_error' => null]);
            $this->logger->info('Removed client {name} a move left on {server}', ['name' => $leftover->remote_name, 'server' => $server->name]);
        }
    }

    /**
     * The target the owner chose, able to take services now.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException on `target_server_id`
     */
    private function target(Server $source, array $input): Server
    {
        $target = Server::query()->find(Input::integer($input, 'target_server_id') ?? 0) ?? throw ValidationException::on('target_server_id', 'سرور مقصد را انتخاب کنید.');
        if ($target->id === $source->id) {
            throw ValidationException::on('target_server_id', 'سرور مقصد باید سروری غیر از همین سرور باشد.');
        }
        $problem = $this->readiness->problem($target);
        if ($problem !== null) {
            throw ValidationException::on('target_server_id', sprintf('«%s»: %s', $target->name, $problem));
        }
        if (ServerMigration::open()->where('source_server_id', $target->id)->exists()) {
            throw ValidationException::on('target_server_id', sprintf('سرویس‌های سرور «%s» خودش در حال انتقال است؛ سرور مقصد دیگری انتخاب کنید.', $target->name));
        }

        return $target;
    }

    /**
     * The plans the owner narrowed the job to; null for every service (none named, or an empty list).
     *
     * @param array<string, mixed> $input
     * @return list<int>|null
     * @throws ValidationException on `plan_ids`
     */
    private static function plansOf(array $input): ?array
    {
        $plans = $input['plan_ids'] ?? null;
        if ($plans === null || $plans === []) {
            return null;
        }
        if (is_array($plans) && count($plans) > self::PLANS_MAX) {
            throw ValidationException::on('plan_ids', sprintf('لیست پلن‌ها بلندتر از حد است؛ حداکثر %s پلن.', Persian::number(self::PLANS_MAX)));
        }
        $ids = [];
        foreach (is_array($plans) ? $plans : [null] as $plan) {
            $ids[] = Input::integerOf($plan) ?? throw ValidationException::on('plan_ids', 'یکی از پلن‌ها پیدا نشد؛ صفحه را دوباره باز کنید.');
        }

        return array_values(array_unique($ids));
    }

    /**
     * A switch the owner may leave out (`$default`), never read as off when it is no switch at all.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException on `$field`: not a boolean
     */
    private static function switchOf(array $input, string $field, bool $default): bool
    {
        $value = $input[$field] ?? $default;
        if (!Input::isBoolean($value)) {
            throw ValidationException::on($field, Validation::NOT_A_SWITCH);
        }

        return Input::truthy($value);
    }

    /**
     * The services a job from the server moves, every shop's: the active ones — and those support switched off, with
     * `$switchedOff` —, only `$plans`' when given.
     *
     * @param list<int>|null $plans
     * @return Builder<Subscription>
     */
    private static function services(int $serverId, bool $switchedOff, ?array $plans): Builder
    {
        $statuses = $switchedOff ? [SubscriptionStatus::Active->value, SubscriptionStatus::Disabled->value] : [SubscriptionStatus::Active->value];

        return Subscription::acrossShops()
            ->where('server_id', $serverId)
            ->whereIn('status', $statuses)
            ->when($plans !== null, static fn(Builder $services) => $services->whereIn('plan_id', $plans));
    }

    /**
     * The services the job may still move: those it covers that existed when it was started.
     *
     * @return Builder<Subscription>
     */
    private static function candidates(ServerMigration $job): Builder
    {
        return self::services($job->source_server_id, $job->include_disabled, $job->plan_ids)->where('id', '<=', $job->upto_subscription_id);
    }

    /**
     * The names of the plans the jobs are narrowed to, by id, in one read: every shop's, those deleted since left out.
     *
     * @param iterable<ServerMigration> $jobs
     * @return array<int, string>
     */
    private static function planNames(iterable $jobs): array
    {
        $ids = [];
        foreach ($jobs as $job) {
            array_push($ids, ...$job->plan_ids ?? []);
        }

        return $ids === [] ? [] : Plan::query()->withoutGlobalScope(CurrentBot::SCOPE)->whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }

    /** «سرور مقصد «هلند»: …», «سرور مبدا «آلمان»: …» — what is wrong, and with which of the two. */
    private static function onServer(string $role, Server $server, string $problem): string
    {
        return "سرور {$role} «{$server->name}»: {$problem}";
    }
}
