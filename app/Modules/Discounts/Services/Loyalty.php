<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Services;

use App\Modules\Discounts\Models\LoyaltyTier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Loyalty tiers («سطح وفاداری»): what a customer spent in the shop (Order::spending()) puts them on the highest tier
 * whose threshold they reached. A tier gives a discount (one of the automatic discounts) and extra cashback (read by
 * Rewards\Services\Cashback). A shop with no tier has no loyalty program — there is no separate switch.
 */
final class Loyalty
{
    /** What the customer spent in the shop, in Toman. */
    public function spent(User $user): string
    {
        return Money::normalize((string) Order::spending()->where('user_id', $user->id)->sum('amount'));
    }

    /**
     * The shop's tiers, the lowest threshold first.
     *
     * @return list<LoyaltyTier>
     */
    public function tiers(): array
    {
        return LoyaltyTier::query()->orderBy('min_spent')->orderBy('id')->get()->all();
    }

    /** The customer's tier now; null for none, no tier at all, or a visitor who has not signed in. */
    public function tierOf(?User $user): ?LoyaltyTier
    {
        if ($user === null) {
            return null;
        }
        $tiers = $this->tiers();

        return $tiers === [] ? null : self::tierFor($this->spent($user), $tiers);
    }

    /**
     * Where the customer stands — the bot's wallet screen, their page in the panels, the website: their tier (null for
     * none), what they spent, and the next tier up with what is missing to reach it (null at the top, or with no tiers).
     *
     * @return array{tier: array{id: int, name: string, discount_percent: int, cashback_percent: int}|null, spent: string, next: array{name: string, min_spent: string, missing: string}|null}
     */
    public function standing(User $user): array
    {
        $tiers = $this->tiers();
        $spent = $this->spent($user);
        $tier = self::tierFor($spent, $tiers);
        $next = self::nextFor($spent, $tiers);

        return [
            'tier' => $tier === null ? null : [
                'id' => $tier->id,
                'name' => $tier->name,
                'discount_percent' => $tier->discount_percent,
                'cashback_percent' => $tier->cashback_percent,
            ],
            'spent' => $spent,
            'next' => $next === null ? null : [
                'name' => $next->name,
                'min_spent' => Money::normalize($next->min_spent),
                'missing' => Money::subtract($next->min_spent, $spent),
            ],
        ];
    }

    /**
     * The tier this order's delivery moved its customer up to, or null. The customer's fulfilled orders delivered before
     * it (by `fulfilled_at`, then id) are counted without it and with it, so each crossing of a threshold belongs to
     * exactly one order and is never announced twice. Two deliveries crossing a threshold in the same second, the later id
     * committing first, each count without the other and announce nothing: a missed congratulation is preferred to a
     * repeated one. An order at no price moves nobody.
     */
    public function promotedBy(Order $order): ?LoyaltyTier
    {
        $fulfilled = $order->fulfilled_at;
        if ($fulfilled === null || !$order->type->forThemselves() || !Money::isPositive($order->amount)) {
            return null;
        }
        $tiers = $this->tiers();
        if ($tiers === []) {
            return null;
        }

        $at = $fulfilled->copy()->utc();
        $before = Money::normalize((string) Order::query()
            ->where('user_id', $order->user_id)
            ->where('status', OrderStatus::Fulfilled->value)
            ->whereIn('type', OrderType::FOR_THEMSELVES)
            ->whereKeyNot($order->id)
            ->where(static fn(Builder $query) => $query
                ->where('fulfilled_at', '<', $at)
                ->orWhere(static fn(Builder $tie) => $tie->where('fulfilled_at', $at)->where('id', '<', $order->id)))
            ->sum('amount'));

        $was = self::tierFor($before, $tiers);
        $now = self::tierFor(Money::add($before, $order->amount), $tiers);

        return $now !== null && $now->id !== $was?->id ? $now : null;
    }

    /**
     * The next tier above `$spent`: the lowest threshold not reached yet; null at the top.
     *
     * @param list<LoyaltyTier> $tiers The lowest threshold first
     */
    public static function nextFor(string $spent, array $tiers): ?LoyaltyTier
    {
        foreach ($tiers as $tier) {
            if (Money::compare($spent, $tier->min_spent) < 0) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The tier `$spent` Toman puts a customer on: the highest threshold reached; null for none.
     *
     * @param list<LoyaltyTier> $tiers The lowest threshold first
     */
    public static function tierFor(string $spent, array $tiers): ?LoyaltyTier
    {
        $reached = null;
        foreach ($tiers as $tier) {
            if (Money::compare($spent, $tier->min_spent) >= 0) {
                $reached = $tier;
            }
        }

        return $reached;
    }
}
