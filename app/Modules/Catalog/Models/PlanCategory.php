<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of plans the customer picks first ("ماهانه", "اقتصادی" …), its name unique in its shop. One switched off is
 * not offered, and its plans are sold under «سایر پلن‌ها» with the uncategorised ones (PlanCategoryService::groups()).
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Plan> $plans
 */
class PlanCategory extends Model
{
    use BelongsToBot;

    protected $table = 'plan_categories';

    protected $fillable = ['name', 'is_active', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * Categories the customer may browse, in display order.
     *
     * @return Builder<static>
     */
    public static function active(): Builder
    {
        return static::query()->where('is_active', true)->oldest('sort')->oldest('id');
    }

    /** @return HasMany<Plan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'category_id');
    }
}
