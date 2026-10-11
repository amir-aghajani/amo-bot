<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The order a website request's Idempotency-Key came to (OrderService::open()), with the payment method it asked for:
 * the same request made again is answered by that order. Several keys may name one order. Kept KEEP_DAYS
 * (ExpireOrdersTask). The primary key is (user_id, key): rows are created and deleted by query, never saved as models.
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
