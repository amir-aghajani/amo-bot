<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The cashback a delivered order earned (Services\Cashback): one per order (`order_id` is unique), with its percent and
 * amount; `reversed_at` is set when the order's refund took it back.
 *
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property int $percent
 * @property string $amount Toman
 * @property Carbon|null $reversed_at When the order's refund took it back
 * @property Carbon $created_at
 * @property-read Order $order
 * @property-read User $user
 */
class Cashback extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'cashbacks';

    protected $fillable = ['order_id', 'user_id', 'percent', 'amount', 'reversed_at'];

    /** @var array<string, string> */
    protected $casts = [
        'order_id' => 'integer',
        'user_id' => 'integer',
        'percent' => 'integer',
        'amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
