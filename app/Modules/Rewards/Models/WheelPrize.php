<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A slice of the lucky wheel («گردونه شانس»): its label, what it gives, and its weight in the draw (Services\LuckyWheel).
 *
 * @property int $id
 * @property int $bot_id
 * @property string $label
 * @property RewardKind $kind
 * @property string|null $amount Toman (wallet), GB (traffic), days (days); null for nothing and a plan
 * @property int|null $plan_id The plan given; null once that plan was deleted
 * @property int $weight
 * @property bool $enabled
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan|null $plan
 */
class WheelPrize extends Model
{
    use BelongsToBot;

    protected $table = 'wheel_prizes';

    protected $fillable = ['label', 'kind', 'amount', 'plan_id', 'weight', 'enabled', 'sort'];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => RewardKind::class,
        'amount' => 'decimal:2',
        'plan_id' => 'integer',
        'weight' => 'integer',
        'enabled' => 'boolean',
        'sort' => 'integer',
    ];

    /** What it gives; null for a plan's slice whose plan was deleted — it can be given to nobody. */
    public function reward(): ?Reward
    {
        return Reward::of($this->kind, $this->amount, $this->plan);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
