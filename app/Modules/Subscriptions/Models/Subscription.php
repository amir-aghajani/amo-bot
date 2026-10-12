<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's service: one client on a panel. `remote_name` is the client's name on the panel and `subscription_url`
 * the one link the customer gets. The counters, quota and term mirror the panel (Services\ProvisioningService writes
 * them, stamped `last_synced_at`); `status` is the shop's.
 *
 * The term (`duration_hours`) starts at the customer's first connection: `expires_at` and `starts_at` stay null until
 * the panel starts the clock (awaitsFirstUse()). A change made on the panel holds the row with a lease (`lease_token`,
 * `leased_until` — Core\Database\Lease), so two changes never interleave.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $plan_id
 * @property bool $is_test a free test account (Services\TestAccounts): no plan, never renewed, removed once it ends while
 *                         its shop cleans up
 * @property int $server_id
 * @property string $remote_name
 * @property string $subscription_url
 * @property SubscriptionStatus $status
 * @property int $traffic_limit_bytes 0 = unlimited
 * @property int $upload_bytes
 * @property int $download_bytes
 * @property int $ip_limit
 * @property int $duration_hours the term in hours (an hourly plan's, or days × 24), counted from the first connection;
 *                               0 = never expires
 * @property Carbon|null $starts_at when the clock started (the first connection); null until then
 * @property Carbon|null $expires_at null = never, or not started yet (awaitsFirstUse())
 * @property Carbon|null $last_synced_at when the panel was last read for it: the moment the numbers above are from
 * @property Carbon|null $disabled_at when support switched it off (status disabled)
 * @property Carbon|null $ended_at when it ended — the deadline it passed, or when the panel said its traffic was used up
 *                                 or its client gone; null while it runs. The automatic removal counts from it.
 * @property bool $auto_renew the customer's «تمدید خودکار»: renewed from the wallet before the deadline (AutoRenewal)
 * @property Carbon|null $renewal_notified_at when the customer was last told the wallet could not pay an automatic renewal
 * @property Carbon|null $expiry_reminded_at when the customer was reminded that it ends soon (ServiceReminders); null = not while it is near
 * @property Carbon|null $traffic_reminded_at when the customer was reminded that its traffic runs low; null = not while it is low
 * @property Carbon|null $period_ends_at when the paid period a renewal was queued behind ends (what is left is capped then)
 * @property int|null $next_period_bytes the most traffic left from period_ends_at on: the renewed traffic
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Plan|null $plan
 * @property-read Server $server
 */
class Subscription extends Model
{
    use BelongsToBot;

    /** An active row in use (runningCount()): its clock runs (the panel set its deadline), or it never ends. */
    private const RUNS = 'expires_at IS NOT NULL OR duration_hours = 0';

    protected $table = 'subscriptions';

    /** A row made in this request reads as the table's default says before it is read back. */
    protected $attributes = ['is_test' => false];

    protected $fillable = [
        'user_id',
        'plan_id',
        'is_test',
        'server_id',
        'remote_name',
        'subscription_url',
        'status',
        'traffic_limit_bytes',
        'upload_bytes',
        'download_bytes',
        'ip_limit',
        'duration_hours',
        'starts_at',
        'expires_at',
        'last_synced_at',
        'disabled_at',
        'ended_at',
        'auto_renew',
        'renewal_notified_at',
        'expiry_reminded_at',
        'traffic_reminded_at',
        'period_ends_at',
        'next_period_bytes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => SubscriptionStatus::class,
        'is_test' => 'boolean',
        'traffic_limit_bytes' => 'integer',
        'upload_bytes' => 'integer',
        'download_bytes' => 'integer',
        'ip_limit' => 'integer',
        'duration_hours' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'disabled_at' => 'datetime',
        'ended_at' => 'datetime',
        'auto_renew' => 'boolean',
        'renewal_notified_at' => 'datetime',
        'expiry_reminded_at' => 'datetime',
        'traffic_reminded_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'next_period_bytes' => 'integer',
        'leased_until' => 'datetime',
    ];

    public function usedBytes(): int
    {
        return $this->upload_bytes + $this->download_bytes;
    }

    /** What is left of the quota; null for an unlimited one. */
    public function remainingBytes(): ?int
    {
        return $this->traffic_limit_bytes > 0 ? max(0, $this->traffic_limit_bytes - $this->usedBytes()) : null;
    }

    /**
     * The part of the traffic left that belongs to the paid period a renewal was queued behind: usable until
     * `period_ends_at`, lost then. 0 when no period is queued.
     */
    public function expiringBytes(): int
    {
        if ($this->period_ends_at === null || $this->next_period_bytes === null) {
            return 0;
        }

        return max(0, ($this->remainingBytes() ?? 0) - $this->next_period_bytes);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active;
    }

    /** The term has not started: the panel starts counting at the customer's first connection. */
    public function awaitsFirstUse(): bool
    {
        return $this->expires_at === null && $this->duration_hours > 0;
    }

    /** The service ends at some point: it has a deadline, or a term waiting for the first connection. */
    public function hasTerm(): bool
    {
        return $this->expires_at !== null || $this->duration_hours > 0;
    }

    public function isExpiredByTime(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(Carbon::now());
    }

    public function isExpiredByTraffic(): bool
    {
        return $this->traffic_limit_bytes > 0 && $this->usedBytes() >= $this->traffic_limit_bytes;
    }

    /** Whether it is among expiringWithin($days): active, no free test, its deadline in the window. */
    public function isExpiringWithin(int $days): bool
    {
        $now = Carbon::now();

        return $this->isActive() && !$this->is_test && $this->expires_at !== null && $this->expires_at->between($now, $now->copy()->addDays($days));
    }

    /**
     * Every bot's subscriptions, whatever shop the code works in: for work on the servers as a whole (a panel's names,
     * the grants, the sync).
     *
     * @return Builder<static>
     */
    public static function acrossShops(): Builder
    {
        return static::query()->withoutGlobalScope(CurrentBot::SCOPE);
    }

    /**
     * Active subscriptions — the current shop's, or narrowing `$query` (acrossShops(), a server's).
     *
     * @param Builder<static>|null $query
     * @return Builder<static>
     */
    public static function active(?Builder $query = null): Builder
    {
        return ($query ?? static::query())->where('status', SubscriptionStatus::Active->value);
    }

    /**
     * SQL counting, in a grouped read of active rows, those in use (their clock runs, or they never end) rather than
     * waiting for their first connection.
     */
    public static function runningCount(): string
    {
        return 'SUM(CASE WHEN ' . self::RUNS . ' THEN 1 ELSE 0 END)';
    }

    /**
     * Active subscriptions whose deadline falls within the next `$days` days; one still waiting for its first connection
     * has no deadline and is not among them, and a free test — which nothing renews — never is.
     *
     * @return Builder<static>
     */
    public static function expiringWithin(int $days): Builder
    {
        $now = Carbon::now();

        return static::active()->where('is_test', false)->whereBetween('expires_at', [$now, $now->copy()->addDays($days)]);
    }

    /** Whether it is over at `$now`, as over() asks the database: ended, gone from its panel, or past its deadline. */
    public function isOver(Carbon $now): bool
    {
        return in_array($this->status, [SubscriptionStatus::Expired, SubscriptionStatus::Deleted], true)
            || ($this->isActive() && $this->expires_at !== null && $this->expires_at->lte($now));
    }

    /**
     * Narrow to the subscriptions that are over at `$now`: ended by time or traffic, gone from their panel, or past their
     * deadline before a sync said so — what the clean-up removes (ended services and free tests, ServiceCleanup), the
     * shops it visits (Tasks\CleanupServicesTask), and the tests list's «ended».
     *
     * @template T of Model
     * @param Builder<T> $query Subscriptions, or a relation's query of them
     * @return Builder<T>
     */
    public static function over(Builder $query, Carbon $now): Builder
    {
        return $query->where(static fn(Builder $over) => $over
            ->whereIn('status', [SubscriptionStatus::Expired->value, SubscriptionStatus::Deleted->value])
            ->orWhere(static fn(Builder $late) => $late->where('status', SubscriptionStatus::Active->value)->where('expires_at', '<=', $now)));
    }

    /** @return BelongsTo<User, $this> The customer, in the subscription's own shop whatever shop the code works in. */
    public function user(): BelongsTo
    {
        $user = $this->belongsTo(User::class);
        $user->withoutGlobalScope(CurrentBot::SCOPE);

        return $user;
    }

    /** @return BelongsTo<Plan, $this> The plan it was sold or last renewed on, in its own shop whatever shop the code works in. */
    public function plan(): BelongsTo
    {
        $plan = $this->belongsTo(Plan::class);
        $plan->withoutGlobalScope(CurrentBot::SCOPE);

        return $plan;
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
