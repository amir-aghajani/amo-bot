<?php

declare(strict_types=1);

namespace App\Modules\Agency\Models;

use App\Modules\Agency\Enums\AgencyRequestKind;
use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's request to become an agent, with a few words about themselves — support approves it on a level or
 * rejects it with a reason —, or an agent's request for their agency's end to be moved on (`kind` renewal), approved with
 * the end support gives.
 *
 * @property int $id
 * @property int $user_id
 * @property AgencyRequestKind $kind
 * @property AgencyRequestStatus $status
 * @property string|null $note What the customer wrote
 * @property int|null $level_id The level given on approval
 * @property Carbon|null $extended_to A renewal's approval: the end it gave; null with no end at all
 * @property string|null $reason Support's word on a rejection
 * @property string|null $reviewer Who decided: the panel login, or a bot admin's @username / tg:<id>
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read AgencyLevel|null $level
 */
class AgencyRequest extends Model
{
    protected $table = 'agency_requests';

    protected $fillable = ['user_id', 'kind', 'status', 'note', 'level_id', 'extended_to', 'reason', 'reviewer', 'decided_at'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'kind' => AgencyRequestKind::class,
        'status' => AgencyRequestStatus::class,
        'level_id' => 'integer',
        'extended_to' => 'datetime',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'kind' => AgencyRequestKind::Join->value,
        'status' => AgencyRequestStatus::Pending->value,
    ];

    /** Whether it asks for an agent's end to be moved on, not to become an agent. */
    public function isRenewal(): bool
    {
        return $this->kind === AgencyRequestKind::Renewal;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        // A customer of the main bot, whichever shop the code runs in.
        $user = $this->belongsTo(User::class);
        $user->withoutGlobalScope(CurrentBot::SCOPE);

        return $user;
    }

    /** @return BelongsTo<AgencyLevel, $this> */
    public function level(): BelongsTo
    {
        return $this->belongsTo(AgencyLevel::class, 'level_id');
    }
}
