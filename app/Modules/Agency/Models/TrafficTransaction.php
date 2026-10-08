<?php

declare(strict_types=1);

namespace App\Modules\Agency\Models;

use App\Modules\Agency\Enums\TrafficTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One line of an agent's prepaid traffic (Agency\Services\TrafficPool): bought, drawn by a sale or a renewal of their
 * bot or by the traffic the shop gave one of its services, given back, or set right by the shop. The shop's as a
 * whole — no bot scope: the agent's bot is named by `bot_id`, whoever reads it.
 *
 * @property int $id
 * @property int $bot_id
 * @property TrafficTransactionType $type
 * @property int $bytes Signed: what came in (+) or went out (-)
 * @property int $balance_after
 * @property string|null $description
 * @property int|null $order_id
 * @property string|null $reviewer The panel's principal behind an adjustment or an extension
 * @property Carbon|null $created_at
 */
class TrafficTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'traffic_transactions';

    protected $fillable = ['bot_id', 'type', 'bytes', 'balance_after', 'description', 'order_id', 'reviewer'];

    /** @var array<string, string> */
    protected $casts = [
        'bot_id' => 'integer',
        'type' => TrafficTransactionType::class,
        'bytes' => 'integer',
        'balance_after' => 'integer',
        'order_id' => 'integer',
    ];
}
