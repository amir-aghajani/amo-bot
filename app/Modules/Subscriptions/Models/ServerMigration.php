<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\MigrationMode;
use App\Modules\Subscriptions\Enums\MigrationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A move of one server's services — every shop's — to another («انتقال سرویس‌ها», Services\ServerMigrations), worked
 * through one service at a time past the `last_subscription_id` cursor by whoever holds its lease (Core\Database\Lease).
 * It covers the services on the source when it was started (`upto_subscription_id`): the active ones, those switched
 * off by support with `include_disabled`, only those of `plan_ids` when given. A panel out of reach holds it at the
 * service it was on (`waiting_reason`); a target that can take no more ends it as failed (`reason`). The database allows
 * one open job per source (`open_source_id`, a generated unique column set while it is queued, running or paused).
 *
 * @property int $id
 * @property int $source_server_id
 * @property int $target_server_id
 * @property MigrationMode $mode
 * @property bool $include_disabled the owner's tick: services support switched off move too (switched off)
 * @property list<int>|null $plan_ids only these plans' services; null for every service
 * @property bool $notify whether each customer is sent the new link
 * @property MigrationStatus $status
 * @property int $upto_subscription_id the newest service when it was started: none made after it is moved
 * @property int $last_subscription_id
 * @property int $total the services it was going to move when started
 * @property int $moved
 * @property int $failed
 * @property int $skipped services it found it could not carry when their turn came (ended, gone, moved away)
 * @property string|null $waiting_reason why it waits at the service it was on (a panel out of reach), for the owner
 * @property string|null $reason why it failed: what keeps the target from taking more
 * @property string|null $last_failure the latest service it could not move, and why
 * @property string|null $reviewer who started it
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 * @property Carbon $created_at
 * @property Carbon|null $started_at when its first turn began
 * @property Carbon|null $finished_at
 * @property-read Server|null $source
 * @property-read Server|null $target
 * @property-read Collection<int, MigrationFailure> $failures
 * @property-read Collection<int, MigrationLeftover> $leftovers
 */
class ServerMigration extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'server_migrations';

    protected $fillable = ['source_server_id', 'target_server_id', 'mode', 'include_disabled', 'plan_ids', 'notify', 'status', 'upto_subscription_id', 'total', 'reviewer'];

    /** @var array<string, string> */
    protected $casts = [
        'source_server_id' => 'integer',
        'target_server_id' => 'integer',
        'mode' => MigrationMode::class,
        'include_disabled' => 'boolean',
        'plan_ids' => 'array',
        'notify' => 'boolean',
        'status' => MigrationStatus::class,
        'upto_subscription_id' => 'integer',
        'last_subscription_id' => 'integer',
        'total' => 'integer',
        'moved' => 'integer',
        'failed' => 'integer',
        'skipped' => 'integer',
        'leased_until' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** @return Builder<static> Jobs not over yet: queued, running or paused. */
    public static function open(): Builder
    {
        return static::query()->whereIn('status', MigrationStatus::values(MigrationStatus::open()));
    }

    /** @return Builder<static> Jobs a turn works on: queued or running. */
    public static function workable(): Builder
    {
        return static::query()->whereIn('status', MigrationStatus::values(MigrationStatus::workable()));
    }

    /** @return BelongsTo<Server, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'source_server_id');
    }

    /** @return BelongsTo<Server, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    /** @return HasMany<MigrationFailure, $this> The services it could not move, the latest first. */
    public function failures(): HasMany
    {
        return $this->hasMany(MigrationFailure::class, 'migration_id')->orderByDesc('id');
    }

    /**
     * @return HasMany<MigrationLeftover, $this> The clients it left on a panel (a rescue's on the source's) still there to
     *                                           remove; those removed since are kept, stamped, for the record
     */
    public function leftovers(): HasMany
    {
        return $this->hasMany(MigrationLeftover::class, 'migration_id')->whereNull('removed_at');
    }
}
