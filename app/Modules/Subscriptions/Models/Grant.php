<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Models;

use App\Modules\Bots\CurrentBot;
use App\Modules\Subscriptions\Enums\GrantAudience;
use App\Modules\Subscriptions\Enums\GrantStatus;
use App\Modules\Users\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Days and traffic the owner gives running services, with a reason the customers read (Services\Grants): for the
 * services that existed when it was issued (`upto_subscription_id`) in its `audience`. It runs as one part per server
 * (`parts`); its status and end are read off its parts.
 *
 * @property int $id
 * @property int $days
 * @property int $traffic_bytes
 * @property string|null $reason what the customers are told with it
 * @property bool $notify whether the customers are told
 * @property bool $include_unstarted the admin's tick: services still waiting for their first connection get it too
 * @property GrantAudience $audience
 * @property int|null $customer_group_id the group, for audience group; null too once the group was deleted
 * @property int $upto_subscription_id the newest service when it was issued: none bought after it gets it
 * @property string|null $reviewer who issued it
 * @property Carbon $created_at
 * @property-read Collection<int, GrantPart> $parts
 * @property-read CustomerGroup|null $customerGroup
 */
class Grant extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'grants';

    protected $fillable = ['days', 'traffic_bytes', 'reason', 'notify', 'include_unstarted', 'audience', 'customer_group_id', 'upto_subscription_id', 'reviewer'];

    /** @var array<string, string> */
    protected $casts = [
        'days' => 'integer',
        'traffic_bytes' => 'integer',
        'notify' => 'boolean',
        'include_unstarted' => 'boolean',
        'audience' => GrantAudience::class,
        'customer_group_id' => 'integer',
        'upto_subscription_id' => 'integer',
    ];

    /** Running while a part runs; otherwise cancelled if any part was stopped, else done. */
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

    /** @return BelongsTo<CustomerGroup, $this> The group whose customers it is for: one of the main shop's, whatever shop reads it. */
    public function customerGroup(): BelongsTo
    {
        $group = $this->belongsTo(CustomerGroup::class);
        $group->withoutGlobalScope(CurrentBot::SCOPE);

        return $group;
    }
}
