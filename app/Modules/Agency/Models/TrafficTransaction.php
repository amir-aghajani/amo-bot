<?php

declare(strict_types=1);

namespace App\Modules\Agency\Models;

use App\Modules\Agency\Enums\TrafficTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of an agent's prepaid traffic ledger (Services\TrafficPool). No shop scope: `bot_id` names the agent's bot,
 * whichever shop reads it.
 *
 * @property int $id
 * @property int $bot_id
 * @property TrafficTransactionType $type
 * @property int $bytes Signed: what came in (+) or went out (-)
 * @property int $balance_after
 * @property string|null $description
 * @property int|null $order_id
 * @property string|null $reviewer The panel's principal behind an adjustment or an extension
 * @property string|null $request_key The Idempotency-Key of the owner's adjustment that wrote it; null for every other line
 * @property Carbon|null $created_at
 */
class TrafficTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'traffic_transactions';

    protected $fillable = ['bot_id', 'type', 'bytes', 'balance_after', 'description', 'order_id', 'reviewer', 'request_key'];

    /** @var array<string, string> */
    protected $casts = [
        'bot_id' => 'integer',
        'type' => TrafficTransactionType::class,
        'bytes' => 'integer',
        'balance_after' => 'integer',
        'order_id' => 'integer',
    ];
}
