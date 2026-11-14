<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Discounts\Models\Sale;
use App\Modules\Discounts\Services\Perks;
use App\Modules\Users\Models\User;

/**
 * The timed sales («فروش ویژه») running now, for a banner: those Discounts takes off every price now (Sale::running()),
 * the soonest to end first and those without an end last — none for a customer who goes without the customers' perks
 * (Perks), whom no sale reaches. What each takes off a plan for its reader is already in the plan's own price
 * (StoreCatalog).
 */
final class StoreSales
{
    public function __construct(private readonly Perks $perks) {}

    /** @return list<array{id: int, name: string, percent: int, ends_at: string|null, plan_ids: list<int>|null, order_types: list<string>|null}> `$user`'s; null for a visitor */
    public function running(?User $user): array
    {
        if ($this->perks->withheld($user)) {
            return [];
        }
        $sales = Sale::running()->get()
            ->sortBy(static fn(Sale $sale): array => [$sale->ends_at === null ? 1 : 0, $sale->ends_at?->getTimestamp() ?? 0, $sale->id])
            ->values();

        return $sales->map(static fn(Sale $sale): array => [
            'id' => $sale->id,
            'name' => $sale->name,
            'percent' => $sale->percent,
            'ends_at' => $sale->ends_at?->toIso8601String(),
            'plan_ids' => $sale->plan_ids,
            'order_types' => $sale->order_types,
        ])->all();
    }
}
