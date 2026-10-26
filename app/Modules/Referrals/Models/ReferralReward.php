<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A referrer's plan reward for a milestone of referrals who bought (Services\ReferralGifts): one per (referrer,
 * milestone). A withheld milestone is a row without an order, so it is never given later.
 *
 * @property int $id
 * @property int $referrer_id
 * @property int $milestone The referrals who bought, a multiple of the admin's number
 * @property int|null $order_id The gift's order; null for a milestone withheld
 * @property Carbon|null $created_at
 */
class ReferralReward extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'referral_rewards';

    protected $fillable = ['referrer_id', 'milestone', 'order_id'];

    /** @var array<string, string> */
    protected $casts = [
        'referrer_id' => 'integer',
        'milestone' => 'integer',
        'order_id' => 'integer',
    ];
}
