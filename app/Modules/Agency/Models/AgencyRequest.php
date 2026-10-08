<?php

declare(strict_types=1);

namespace App\Modules\Agency\Models;

use App\Modules\Agency\Enums\AgencyRequestStatus;
use App\Modules\Bots\CurrentBot;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer asking to become an agent, with a few words about themselves; support approves it — on a level — or
 * rejects it, with a reason the customer reads.
 *
 * @property int $id
 * @property int $user_id
 * @property AgencyRequestStatus $status
 * @property string|null $note What the customer wrote
 * @property int|null $level_id The level given on approval
 * @property string|null $reason Support's word on a rejection
 * @property string|null $reviewer Who decided: the panel login, or a bot admin's @username / tg:<id>
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read AgencyLevel|null $level
 */
class AgencyRequest extends Model
{
    protected $table = 'agency_requests';

    protected $fillable = ['user_id', 'status', 'note', 'level_id', 'reason', 'reviewer', 'decided_at'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'status' => AgencyRequestStatus::class,
        'level_id' => 'integer',
        'decided_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => AgencyRequestStatus::Pending->value,
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        // A customer of the main bot, whichever shop is being worked in.
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
