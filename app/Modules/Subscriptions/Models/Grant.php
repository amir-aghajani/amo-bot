<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\GrantAudience;
use App\Modules\Subscriptions\Enums\GrantStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Days and traffic the admin gives running services — an outage made good, a gift — with the reason the customers read:
 * on every server, the agents' services only, or one server's (`audience`), for the services there when it was issued
 * (`upto_subscription_id`). It is given as a part per server it reaches (`parts`), each worked through on its own by
 * Services\Grants; a grant runs while one of its parts does, and its status and its end are its parts'.
 *
 * @property int $id
 * @property int $days
 * @property int $traffic_bytes
 * @property string|null $reason what the customers are told, with it
 * @property bool $notify whether the customers hear of it in the bot
 * @property bool $include_unstarted the admin's tick: services still waiting for their first connection get it too
 * @property GrantAudience $audience
 * @property int $upto_subscription_id the newest service when it was issued: none bought after it gets it
 * @property string|null $reviewer the panel login that issued it
 * @property Carbon $created_at
 * @property-read Collection<int, GrantPart> $parts
 */
class Grant extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'grants';

    protected $fillable = ['days', 'traffic_bytes', 'reason', 'notify', 'include_unstarted', 'audience', 'upto_subscription_id', 'reviewer'];

    /** @var array<string, string> */
    protected $casts = [
        'days' => 'integer',
        'traffic_bytes' => 'integer',
        'notify' => 'boolean',
        'include_unstarted' => 'boolean',
        'audience' => GrantAudience::class,
        'upto_subscription_id' => 'integer',
    ];

    /** Running while a part runs; cancelled once none does and one was stopped (the whole grant, or a part from its server's page); done otherwise. */
    public function status(): GrantStatus
    {
        $statuses = $this->parts->map(static fn(GrantPart $part): GrantStatus => $part->status);

        return match (true) {
            $statuses->contains(GrantStatus::Running) => GrantStatus::Running,
            $statuses->contains(GrantStatus::Cancelled) => GrantStatus::Cancelled,
            default => GrantStatus::Done,
        };
    }

    /** When its last part ended; null while one runs. */
    public function finishedAt(): ?Carbon
    {
        $last = $this->status() === GrantStatus::Running ? null : $this->parts->max('finished_at');

        return $last instanceof Carbon ? $last : null;
    }

    /** @return HasMany<GrantPart, $this> Its part on each server, in server order. */
    public function parts(): HasMany
    {
        return $this->hasMany(GrantPart::class)->orderBy('server_id');
    }
}
