<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A sellable package: duration + traffic + price, offered on one or more servers (PlanServer).
 * The customer picks the server at purchase time; a plan without servers cannot be bought.
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property string $price
 * @property int $duration_days 0 = no time limit
 * @property float $traffic_gb 0 = unlimited
 * @property int $ip_limit 0 = unlimited
 * @property bool $is_active
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PlanServer> $servers
 * @property-read PlanCategory|null $category
 */
class Plan extends Model
{
    use BelongsToBot;

    protected $table = 'plans';

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'duration_days',
        'traffic_gb',
        'ip_limit',
        'is_active',
        'sort',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'category_id' => 'integer',
        'price' => 'decimal:2',
        'duration_days' => 'integer',
        'traffic_gb' => 'float',
        'ip_limit' => 'integer',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /** @return BelongsTo<PlanCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(PlanCategory::class, 'category_id');
    }

    /** The quota as the panel takes it: binary gigabytes, 0 = unlimited. */
    public function trafficBytes(): int
    {
        return Traffic::bytesOfGb($this->traffic_gb);
    }

    /**
     * Plans customers can currently buy, in display order.
     *
     * @return Builder<static>
     */
    public static function active(): Builder
    {
        return static::query()->where('is_active', true)->oldest('sort')->oldest('id');
    }

    /**
     * Where the plan is sold, in the order the customer sees the servers.
     *
     * @return HasMany<PlanServer, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(PlanServer::class)->orderBy('sort')->orderBy('id');
    }
}
