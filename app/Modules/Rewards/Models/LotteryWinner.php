<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A winner of a night's draw: who won, what they were given (as the customer reads it), and where it went (an order or a service).
 *
 * @property int $id
 * @property int $lottery_draw_id
 * @property int $user_id
 * @property string|null $outcome What they were given, as the customer reads it
 * @property int|null $order_id
 * @property int|null $subscription_id
 * @property Carbon $created_at
 * @property-read User $user
 * @property-read Order|null $order
 * @property-read Subscription|null $subscription
 */
class LotteryWinner extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'lottery_winners';

    protected $fillable = ['lottery_draw_id', 'user_id', 'outcome', 'order_id', 'subscription_id'];

    /** @var array<string, string> */
    protected $casts = [
        'lottery_draw_id' => 'integer',
        'user_id' => 'integer',
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
