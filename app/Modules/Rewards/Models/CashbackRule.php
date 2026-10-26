<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Users\Models\CustomerGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cashback rule (Services\Cashback): `percent` of a delivered order's price back to the wallet, for the kinds of order
 * it names (null: purchases and renewals), customers of its group (null: anyone) paying by its method (null: any).
 *
 * @property int $id
 * @property int $percent 1 to 100
 * @property list<string>|null $order_types OrderType values; null — purchases and renewals
 * @property int|null $customer_group_id
 * @property int|null $payment_method_id
 * @property bool $enabled
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CustomerGroup|null $customerGroup
 * @property-read PaymentMethod|null $paymentMethod
 */
class CashbackRule extends Model
{
    use BelongsToBot;

    /** The kinds a rule that names none is for. */
    public const DEFAULT_TYPES = [OrderType::Purchase->value, OrderType::Renewal->value];

    protected $table = 'cashback_rules';

    protected $fillable = ['percent', 'order_types', 'customer_group_id', 'payment_method_id', 'enabled', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'percent' => 'integer',
        'order_types' => 'array',
        'customer_group_id' => 'integer',
        'payment_method_id' => 'integer',
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];

    /** @return list<string> The kinds of order it is for. */
    public function types(): array
    {
        return $this->order_types ?? self::DEFAULT_TYPES;
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
