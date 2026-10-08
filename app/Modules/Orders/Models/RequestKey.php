<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A request of the website that ordered — by its key (its `Idempotency-Key`, one a customer) — and the order it came to:
 * the one it made, the open one of the same thing it found, or the one its key had made before (OrderService::open()) —,
 * with the way to pay it asked for. The same request made again is answered by that order, never a second one
 * (OrderService::keyed()), and never paid another way: a key is one checkout attempt, its way to pay part of it (another
 * is refused). Several keys may name one order — two checkout attempts that paid the same open order. Kept KEEP_DAYS
 * (ExpireOrdersTask): a request made again after that is a new one. Its key is the pair (customer, key): rows are only
 * made and then deleted by query, never saved again as models.
 *
 * @property int $user_id
 * @property string $key
 * @property int $order_id
 * @property int|null $payment_method_id Null only for a key kept before keys kept their way to pay
 * @property Carbon $created_at
 */
class RequestKey extends Model
{
    use BelongsToBot;

    /** How long a request's key answers for the order it came to. */
    public const KEEP_DAYS = 7;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'request_keys';

    protected $fillable = ['user_id', 'key', 'order_id', 'payment_method_id'];

    /** @var array<string, string> */
    protected $casts = [
        'user_id' => 'integer',
        'order_id' => 'integer',
        'payment_method_id' => 'integer',
    ];
}
