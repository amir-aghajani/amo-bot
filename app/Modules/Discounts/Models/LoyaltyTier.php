<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A loyalty tier («سطح وفاداری»): customers who spent at least `min_spent` are on the highest tier they reached
 * (Services\Loyalty); it gives a discount and extra cashback.
 *
 * @property int $id
 * @property string $name
 * @property string $min_spent Toman
 * @property int $discount_percent 0 none
 * @property int $cashback_percent 0 none
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LoyaltyTier extends Model
{
    use BelongsToBot;

    protected $table = 'loyalty_tiers';

    protected $fillable = ['name', 'min_spent', 'discount_percent', 'cashback_percent', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'min_spent' => 'decimal:2',
        'discount_percent' => 'integer',
        'cashback_percent' => 'integer',
        'sort' => 'integer',
    ];
}
