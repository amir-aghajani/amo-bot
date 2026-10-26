<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Orders\Models\Order;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One spin of the lucky wheel: the slice it landed on as it was then, and what came of it — `outcome` null when the
 * prize was given, else why not (its cost was refunded). Only given spins count for the cooldown (Services\LuckyWheel).
 *
 * @property int $id
 * @property int $bot_id
 * @property int $user_id
 * @property int|null $wheel_prize_id The slice; null once it was deleted
 * @property RewardKind $kind
 * @property string $label
 * @property string|null $amount
 * @property string|null $outcome Why the prize was not given; null — given
 * @property int|null $order_id A plan's order
 * @property int|null $subscription_id The service traffic or days went to, or a plan's service
 * @property Carbon|null $created_at
 * @property-read User $user
 * @property-read Order|null $order
 * @property-read Subscription|null $subscription
 */
class WheelSpin extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'wheel_spins';

    protected $fillable = ['user_id', 'wheel_prize_id', 'kind', 'label', 'amount', 'outcome', 'order_id', 'subscription_id'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'wheel_prize_id' => 'integer',
        'kind' => RewardKind::class,
        'amount' => 'decimal:2',
        'order_id' => 'integer',
        'subscription_id' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
