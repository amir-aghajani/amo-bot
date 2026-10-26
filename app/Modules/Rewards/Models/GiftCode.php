<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Models\Order;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\GiftCodeKind;
use App\Modules\Rewards\Enums\GiftCodeStatus;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A gift code («کد هدیه»): Toman for the wallet or a plan's free service, redeemed once (Services\GiftCodes). Kept as it
 * is matched: capital letters and digits only.
 *
 * @property int $id
 * @property int $bot_id
 * @property string $code
 * @property string|null $batch The label it was made under («نوروز ۱۴۰۵»)
 * @property GiftCodeKind $kind
 * @property string|null $amount Toman, for the wallet
 * @property int|null $plan_id The plan given, for a service; null once that plan was deleted
 * @property Carbon|null $expires_at The last moment it may be taken; null — never ends
 * @property int|null $redeemed_by Who took it; null once their account is gone
 * @property Carbon|null $redeemed_at When it was taken — what "taken" is
 * @property int|null $order_id The order a plan's service was given by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan|null $plan
 * @property-read User|null $redeemer
 * @property-read Order|null $order
 */
class GiftCode extends Model
{
    use BelongsToBot;

    protected $table = 'gift_codes';

    protected $fillable = ['code', 'batch', 'kind', 'amount', 'plan_id', 'expires_at'];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => GiftCodeKind::class,
        'amount' => 'decimal:2',
        'plan_id' => 'integer',
        'expires_at' => 'datetime',
        'redeemed_by' => 'integer',
        'redeemed_at' => 'datetime',
        'order_id' => 'integer',
    ];

    /** @return Builder<static> Codes nobody took that have not run out. */
    public static function usable(): Builder
    {
        return self::whereUsable(static::query());
    }

    /**
     * Narrows `$query` to codes nobody took that have not run out.
     *
     * @template TQuery of Builder<static>
     * @param TQuery $query
     * @return TQuery
     */
    public static function whereUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('redeemed_at')
            ->where(static fn(Builder $open) => $open->whereNull('expires_at')->orWhere('expires_at', '>', now()->utc()));
    }

    /** What it gives; null for a plan code whose plan was deleted. */
    public function reward(): ?Reward
    {
        return Reward::of($this->kind->reward(), $this->amount, $this->plan);
    }

    public function status(): GiftCodeStatus
    {
        return match (true) {
            $this->redeemed_at !== null => GiftCodeStatus::Redeemed,
            $this->isExpired() => GiftCodeStatus::Expired,
            default => GiftCodeStatus::Unused,
        };
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo(now());
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
