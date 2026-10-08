<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Payments\Models\Payment;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one payment earned its customer's referrer (ReferralService::reward()): at most one row per payment, with whom
 * it was credited to — the customer's `referred_by` as it was earned, kept: two accounts merged since may give the
 * customer another, and what was earned stays its referrer's —, the rate in force then and the commission credited to
 * the referrer's wallet. Who paid and how much are the payment's: its order's customer.
 *
 * @property int $id
 * @property int $payment_id
 * @property int $referrer_id Whose wallet it was credited to
 * @property int $rate The commission percent then in force
 * @property string $commission What the referrer's wallet got
 * @property Carbon|null $notified_at When the referrer was told (claimed by the one message)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Payment $payment
 * @property-read User $referrer
 */
class ReferralCommission extends Model
{
    use BelongsToBot;

    /** What withPayer() calls the customer who paid. */
    public const PAYER = 'payers';

    protected $table = 'referral_commissions';

    protected $fillable = ['payment_id', 'referrer_id', 'rate', 'commission', 'notified_at'];

    /** @var array<string, string> */
    protected $casts = [
        'referrer_id' => 'integer',
        'rate' => 'integer',
        'commission' => 'decimal:2',
        'notified_at' => 'datetime',
    ];

    /**
     * Commissions beside the customer who paid each — joined as `payers` (PAYER): the payment's order's customer.
     *
     * @return Builder<static>
     */
    public static function withPayer(): Builder
    {
        return static::query()
            ->join('payments', 'payments.id', '=', 'referral_commissions.payment_id')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->join('users as ' . self::PAYER, self::PAYER . '.id', '=', 'orders.user_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
}
