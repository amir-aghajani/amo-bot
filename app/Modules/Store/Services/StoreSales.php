<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Models\Sale;
use App\Modules\Discounts\Services\Perks;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The timed sales («فروش ویژه») running now, for a banner: those Discounts takes off every price now (Sale::running()),
 * the soonest to end first and those without an end last — none for a customer who goes without the customers' perks
 * (Perks), whom no sale reaches. What each takes off a plan for its reader is already in the plan's own price
 * (StoreCatalog). A sale's `plan_ids` are the plans on sale now (Plan::active(): switched on, not kept from sale by the
 * owner) it holds for — those it names and those filed under the categories it names (`category_ids`) —, null for
 * every plan and empty when none of them is on sale, so a website reading the plans alone reads them right.
 */
final class StoreSales
{
    public function __construct(private readonly Perks $perks) {}

    /** @return list<array{id: int, name: string, percent: int, ends_at: string|null, plan_ids: list<int>|null, category_ids: list<int>|null, order_types: list<string>|null}> `$user`'s; null for a visitor */
    public function running(?User $user): array
    {
        if ($this->perks->withheld($user)) {
            return [];
        }
        $sales = Sale::running()->get()
            ->sortBy(static fn(Sale $sale): array => [$sale->ends_at === null ? 1 : 0, $sale->ends_at?->getTimestamp() ?? 0, $sale->id])
            ->values();
        // The plans on sale now that the sales name, or file under the categories they name, read once for them all.
        $named = $sales->flatMap(static fn(Sale $sale): array => $sale->plan_ids ?? [])->unique()->values()->all();
        $categoryIds = $sales->flatMap(static fn(Sale $sale): array => $sale->category_ids ?? [])->unique()->values()->all();
        $onSale = $named === [] && $categoryIds === [] ? collect() : Plan::active()->reorder()
            ->where(static fn(Builder $query) => $query->whereIn('id', $named)->orWhereIn('category_id', $categoryIds))
            ->get(['id', 'category_id']);

        return $sales->map(static function (Sale $sale) use ($onSale): array {
            $plans = null;
            if ($sale->plan_ids !== null || $sale->category_ids !== null) {
                $plans = array_values($onSale->filter(static fn(Plan $plan): bool => $sale->takesPlan($plan->id, $plan->category_id))
                    ->map(static fn(Plan $plan): int => $plan->id)->all());
                sort($plans);
            }

            return [
                'id' => $sale->id,
                'name' => $sale->name,
                'percent' => $sale->percent,
                'ends_at' => $sale->ends_at?->toIso8601String(),
                'plan_ids' => $plans,
                'category_ids' => $sale->category_ids,
                'order_types' => $sale->order_types,
            ];
        })->all();
    }
}
