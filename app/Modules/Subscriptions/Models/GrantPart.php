<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\GrantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A grant's part on one server: the services there it reaches, worked through by Services\Grants one at a time past the
 * `last_subscription_id` cursor, by whoever holds its lease (`lease_token`, until `leased_until` — Core\Database\Lease).
 * A panel out of reach holds it at the service it was on (`waiting_reason`) — the grant's other servers go on. One part
 * runs on a server at a time: the database refuses a second (`running_server_id`, the server while the part runs).
 *
 * @property int $id
 * @property int $grant_id
 * @property int $server_id
 * @property GrantStatus $status
 * @property int $last_subscription_id
 * @property int $total the services it was going to reach when issued
 * @property int $granted
 * @property int $skipped services the grant was not for when their turn came (gone, switched off, ended, given nothing)
 * @property int $failed
 * @property string|null $waiting_reason why it waits at the service it was on — its panel out of reach —, in the admin's words
 * @property string|null $last_failure the latest service the panel refused, and why
 * @property string|null $lease_token
 * @property Carbon|null $leased_until
 * @property Carbon|null $finished_at
 * @property-read Grant $grant
 * @property-read Server|null $server
 */
class GrantPart extends Model
{
    public $timestamps = false;

    protected $table = 'grant_parts';

    protected $fillable = ['grant_id', 'server_id', 'status', 'total'];

    /** @var array<string, string> */
    protected $casts = [
        'grant_id' => 'integer',
        'server_id' => 'integer',
        'status' => GrantStatus::class,
        'last_subscription_id' => 'integer',
        'total' => 'integer',
        'granted' => 'integer',
        'skipped' => 'integer',
        'failed' => 'integer',
        'leased_until' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isRunning(): bool
    {
        return $this->status === GrantStatus::Running;
    }

    /** @return Builder<static> Parts still being worked through. */
    public static function running(): Builder
    {
        return static::query()->where('status', GrantStatus::Running->value);
    }

    /** @return BelongsTo<Grant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
