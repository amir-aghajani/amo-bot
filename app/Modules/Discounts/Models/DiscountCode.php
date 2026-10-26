<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Discounts\Enums\DiscountType;
use App\Modules\Discounts\Models\Concerns\DiscountScope;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A discount code a customer types at a checkout («کد تخفیف»), kept and matched in upper case (Services\DiscountCodes).
 * A use is an order made with it that is neither cancelled nor refunded, so an order that expires unpaid, or is refunded,
 * gives its use back.
 *
 * @property int $id
 * @property string $code Upper case
 * @property string|null $note The admin's own words about it
 * @property DiscountType $type
 * @property string $value A percent (whole, 1–100) or Toman; 0 for a free one
 * @property string|null $max_discount The most Toman a percent takes off; null no cap
 * @property string|null $min_amount The least an order must come to after the automatic discount; null none
 * @property list<string>|null $order_types OrderType values; null every kind a customer buys for themselves
 * @property list<int>|null $plan_ids
 * @property list<int>|null $server_ids
 * @property bool $first_purchase_only Only for a customer who has bought nothing yet (Order::boughtBy()) — asked again as its order is delivered
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_uses
 * @property int|null $per_user
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $uses withCount: its orders neither cancelled nor refunded
 * @property-read bool|null $kept withExists: an order other than a cancelled one names it — kept for that order's history (Services\DiscountCodes::delete())
 */
class DiscountCode extends Model
{
    use BelongsToBot;
    use DiscountScope;

    protected $table = 'discount_codes';

    protected $fillable = [
        'code',
        'note',
        'type',
        'value',
        'max_discount',
        'min_amount',
        'order_types',
        'plan_ids',
        'server_ids',
        'first_purchase_only',
        'starts_at',
        'ends_at',
        'max_uses',
        'per_user',
        'enabled',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => DiscountType::class,
        'value' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'order_types' => 'array',
        'plan_ids' => 'array',
        'server_ids' => 'array',
        'first_purchase_only' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'max_uses' => 'integer',
        'per_user' => 'integer',
        'enabled' => 'boolean',
    ];

    protected $attributes = [
        'enabled' => true,
        'first_purchase_only' => false,
    ];

    /** Whether it applies to an order on server `$serverId` (null: none chosen, which only "every server" reaches). */
    public function takesServer(?int $serverId): bool
    {
        return $this->server_ids === null || ($serverId !== null && in_array($serverId, $this->server_ids, true));
    }

    /**
     * Every order made with it, cancelled and refunded ones too: its uses are the others.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
