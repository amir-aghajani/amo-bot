<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A broadcast message pinned in a customer's chat, kept so «لغو پین» can take it off. Keyed by (broadcast, user): rows
 * are inserted and deleted by query, never saved as models.
 *
 * @property int $broadcast_id
 * @property int $user_id
 * @property int $message_id
 * @property-read User $user
 */
class BroadcastPin extends Model
{
    public $timestamps = false;
    public $incrementing = false;

    protected $table = 'broadcast_pins';

    protected $fillable = ['broadcast_id', 'user_id', 'message_id'];

    /** @var array<string, string> */
    protected $casts = [
        'broadcast_id' => 'integer',
        'user_id' => 'integer',
        'message_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
