<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use Illuminate\Database\Eloquent\Collection;

/**
 * A plan as the shop's website offers it: what it gives and what it costs, the category the shop offers it under and the
 * servers a customer may pick it on today («لوکیشن» in the bot) — nothing of the panels behind them, nor why another
 * server is not among them.
 */
final class PlanPresenter
{
    /**
     * `$category` is the one it is offered under — none for «سایر پلن‌ها»: no category, or one switched off —, `$choices`
     * the servers it can be delivered on now, in the plan's order (ServerSelector::offers()).
     *
     * @param list<array{server: Server, inbounds: Collection<int, ServerInbound>}> $choices
     * @return array{id: int, name: string, description: string|null, price: string, traffic_gb: float, duration_days: int, devices: int, category_id: int|null, locations: list<array{id: int, name: string}>}
     */
    public static function present(Plan $plan, ?PlanCategory $category, array $choices): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'traffic_gb' => $plan->traffic_gb,
            'duration_days' => $plan->duration_days,
            'devices' => $plan->ip_limit,
            'category_id' => $category?->id,
            'locations' => array_map(static fn(array $choice): array => ['id' => $choice['server']->id, 'name' => $choice['server']->name], $choices),
        ];
    }

    /**
     * A category the catalogue files plans under; null for the group of the rest («سایر پلن‌ها»).
     *
     * @return array{id: int, name: string}|null
     */
    public static function category(?PlanCategory $category): ?array
    {
        return $category === null ? null : ['id' => $category->id, 'name' => $category->name];
    }
}
