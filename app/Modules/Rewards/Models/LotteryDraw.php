<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One night's draw («قرعه‌کشی شبانه», Services\Lottery): its day (unique per shop — the row is inserted before drawing,
 * so a day is drawn once), its entries, its prize as set then, and its winners.
 *
 * @property int $id
 * @property string $day The shop's day, "YYYY-MM-DD" in its zone
 * @property int $entries
 * @property RewardKind $prize_kind
 * @property string|null $prize_amount Toman, GB or days
 * @property int|null $prize_plan_id
 * @property Carbon|null $created_at When it was drawn: its entries' window ends just before, where the next draw's begins (Services\Lottery)
 * @property-read Plan|null $prizePlan
 * @property-read Collection<int, LotteryWinner> $winners
 */
class LotteryDraw extends Model
{
    use BelongsToBot;

    public const UPDATED_AT = null;

    protected $table = 'lottery_draws';

    protected $fillable = ['day', 'entries', 'prize_kind', 'prize_amount', 'prize_plan_id'];

    /** @var array<string, string> */
    protected $casts = [
        'entries' => 'integer',
        'prize_kind' => RewardKind::class,
        'prize_amount' => 'decimal:2',
        'prize_plan_id' => 'integer',
    ];

    /** Its prize as it is to be given; null for a plan deleted since. */
    public function reward(): ?Reward
    {
        return Reward::of($this->prize_kind, $this->prize_amount, $this->prizePlan);
    }

    /** @return BelongsTo<Plan, $this> */
    public function prizePlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'prize_plan_id');
    }

    /** @return HasMany<LotteryWinner, $this> In the order they were drawn. */
    public function winners(): HasMany
    {
        return $this->hasMany(LotteryWinner::class)->orderBy('id');
    }
}
