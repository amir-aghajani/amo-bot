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
 * A provisioned client on a panel — what the customer actually connects with. `remote_name` is the client's identifier
 * on the panel and `subscription_url` the one link the customer gets, as the panel last served it. The counters, the
 * quota and the term mirror the panel (Services\ProvisioningService writes them from what it answered, and when:
 * `last_synced_at`); `status` is the shop's.
 *
 * The term (`duration_days`) starts counting at the customer's first connection: `expires_at` (and `starts_at`) stay
 * null until the panel has started the clock — see awaitsFirstUse(). A change made on the panel through the shop holds
 * the row for its length (`lease_token`, `leased_until` — Core\Database\Lease), so two of them never interleave.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $plan_id
 * @property int $server_id
 * @property string $remote_name
 * @property string $subscription_url
 * @property SubscriptionStatus $status
 * @property int $traffic_limit_bytes 0 = unlimited
 * @property int $upload_bytes
 * @property int $download_bytes
 * @property int $ip_limit
 * @property int $duration_days the term, counted from the first connection; 0 = never expires
 * @property Carbon|null $starts_at when the clock started (the first connection); null until then
 * @property Carbon|null $expires_at null = never, or not started yet (awaitsFirstUse())
 * @property Carbon|null $last_synced_at when the panel was last read for it — the moment the numbers above are from
 * @property Carbon|null $disabled_at when support switched it off (status disabled)
 * @property bool $auto_renew the customer's «تمدید خودکار»: renewed from the wallet before the deadline (AutoRenewal)
 * @property Carbon|null $renewal_notified_at when the customer was last told the wallet could not pay an automatic renewal
 * @property Carbon|null $expiry_reminded_at when the customer was reminded that it ends soon (ServiceReminders); null = not while it is near
 * @property Carbon|null $traffic_reminded_at when the customer was reminded that its traffic runs low; null = not while it is low
 * @property Carbon|null $period_ends_at the paid period a renewal was queued behind ends then (the shop caps what is left)
 * @property int|null $next_period_bytes from period_ends_at on, at most this remains: the renewed traffic
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

    /** What an active row in use is (runningCount()): its clock runs (the panel set its deadline), or it never ends. */
    private const RUNS = 'expires_at IS NOT NULL OR duration_days = 0';

    protected $table = 'subscriptions';

    protected $fillable = [
        'user_id',
        'plan_id',
        'server_id',
        'remote_name',
        'subscription_url',
        'status',
        'traffic_limit_bytes',
        'upload_bytes',
        'download_bytes',
        'ip_limit',
        'duration_days',
        'starts_at',
        'expires_at',
        'last_synced_at',
        'disabled_at',
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
        'traffic_limit_bytes' => 'integer',
        'upload_bytes' => 'integer',
        'download_bytes' => 'integer',
        'ip_limit' => 'integer',
        'duration_days' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'disabled_at' => 'datetime',
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
     * What of the traffic left belongs to the paid period a renewal was queued behind: usable until `period_ends_at`,
     * gone then (the shop does not carry it). 0 when no such period is pending.
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
        return $this->expires_at === null && $this->duration_days > 0;
    }

    /** The service ends at some point: it has a deadline, or a term waiting for the first connection. */
    public function hasTerm(): bool
    {
        return $this->expires_at !== null || $this->duration_days > 0;
    }

    public function isExpiredByTime(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lte(Carbon::now());
    }

    public function isExpiredByTraffic(): bool
    {
        return $this->traffic_limit_bytes > 0 && $this->usedBytes() >= $this->traffic_limit_bytes;
    }

    /** Whether it is among expiringWithin($days): active, its deadline in the window. */
    public function isExpiringWithin(int $days): bool
    {
        $now = Carbon::now();

        return $this->isActive() && $this->expires_at !== null && $this->expires_at->between($now, $now->copy()->addDays($days));
    }

    /**
     * Every bot's subscriptions, whatever shop the code works in: what the shop's servers serve as a whole — a panel's
     * names, the grants, the periodic sync.
     *
     * @return Builder<static>
     */
    public static function acrossShops(): Builder
    {
        return static::query()->withoutGlobalScope(CurrentBot::SCOPE);
    }

    /**
     * Subscriptions that are usable now — the shop's, or of `$query` (acrossShops(), a server's).
     *
     * @param Builder<static>|null $query
     * @return Builder<static>
     */
    public static function active(?Builder $query = null): Builder
    {
        return ($query ?? static::query())->where('status', SubscriptionStatus::Active->value);
    }

    /**
     * How many of the active rows a grouped read counts are in use — their clock runs, or they never end —, not still
     * waiting for their first connection (awaitsFirstUse()): a tally of every server's services in one read.
     */
    public static function runningCount(): string
    {
        return 'SUM(CASE WHEN ' . self::RUNS . ' THEN 1 ELSE 0 END)';
    }

    /**
     * Active subscriptions whose deadline falls within the next `$days` days; one still waiting for its first connection
     * has no deadline and is not among them.
     *
     * @return Builder<static>
     */
    public static function expiringWithin(int $days): Builder
    {
        $now = Carbon::now();

        return static::active()->whereBetween('expires_at', [$now, $now->copy()->addDays($days)]);
    }

    /** @return BelongsTo<User, $this> The customer — the subscription's own shop's, whatever shop the code works in. */
    public function user(): BelongsTo
    {
        $user = $this->belongsTo(User::class);
        $user->withoutGlobalScope(CurrentBot::SCOPE);

        return $user;
    }

    /** @return BelongsTo<Plan, $this> The plan it was sold or last renewed on — its own shop's, whatever shop the code works in. */
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
