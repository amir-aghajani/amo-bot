<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Referrals\Enums\AlertReason;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fake-referral alert on a referrer (Services\ReferralAlerts): why, and how many referrals there were when raised.
 * While it stands the referrer earns no gifts; clearing it deletes the row.
 *
 * @property int $id
 * @property int $referrer_id
 * @property AlertReason $reason
 * @property int $count
 * @property Carbon|null $created_at
 * @property-read User $referrer
 */
class ReferralAlert extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'referral_alerts';

    protected $fillable = ['referrer_id', 'reason', 'count'];

    /** @var array<string, string> */
    protected $casts = [
        'referrer_id' => 'integer',
        'reason' => AlertReason::class,
        'count' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
