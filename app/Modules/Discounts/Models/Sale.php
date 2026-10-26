<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Discounts\Models\Concerns\DiscountScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A timed sale («فروش ویژه»): `percent` off the plans and kinds of order it names, within its window. One of the
 * automatic discounts (Services\Discounts), of which only the largest applies.
 *
 * @property int $id
 * @property string $name What the customer reads: «فروش ویژه نوروز»
 * @property int $percent 1–100
 * @property list<string>|null $order_types
 * @property list<int>|null $plan_ids
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Sale extends Model
{
    use BelongsToBot;
    use DiscountScope;

    protected $table = 'sales';

    protected $fillable = ['name', 'percent', 'order_types', 'plan_ids', 'starts_at', 'ends_at', 'enabled'];

    /** @var array<string, string> */
    protected $casts = [
        'percent' => 'integer',
        'order_types' => 'array',
        'plan_ids' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'enabled' => 'boolean',
    ];

    protected $attributes = ['enabled' => true];

    /**
     * The shop's sales running now: switched on, started and not ended.
     *
     * @return Builder<static>
     */
    public static function running(): Builder
    {
        $now = now()->utc();

        return static::query()
            ->where('enabled', true)
            ->where(static fn(Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(static fn(Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now));
    }
}
