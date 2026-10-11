<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Referrals\Enums\StartGiftTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A newcomer's start gift (Services\ReferralGifts), decided once (`invitee_id` is unique). A withheld gift is a row of
 * zeros, so it is never paid later.
 *
 * @property int $id
 * @property int $invitee_id
 * @property int $referrer_id
 * @property string $referrer_amount What the referrer's wallet got, Toman (0: nothing)
 * @property string $invitee_amount What the newcomer's wallet got, Toman (0: nothing)
 * @property StartGiftTrigger $trigger
 * @property Carbon $created_at
 */
class ReferralBonus extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'referral_bonuses';

    protected $fillable = ['invitee_id', 'referrer_id', 'referrer_amount', 'invitee_amount', 'trigger'];

    /** @var array<string, string> */
    protected $casts = [
        'invitee_id' => 'integer',
        'referrer_id' => 'integer',
        'referrer_amount' => 'decimal:2',
        'invitee_amount' => 'decimal:2',
        'trigger' => StartGiftTrigger::class,
    ];
}
