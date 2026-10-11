<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Models\PlanCategory;
use App\Modules\Discounts\Services\DiscountForm;
use App\Modules\Orders\DTO\Buyer;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Models\ServerInbound;
use App\Modules\Subscriptions\Services\ServiceTerms;
use Illuminate\Database\Eloquent\Collection;

/**
 * A plan as the website offers it: what it gives and costs, its category, and the servers it may be picked on today
 * («لوکیشن» in the bot) — nothing of the panels behind them.
 */
final class PlanPresenter
{
    /**
     * `$category` is the one it is offered under (null for «سایر پلن‌ها»), `$choices` the servers it can be delivered on
     * now. `$buyer` decides which other prices are the reader's (each null otherwise); `price` is the same for everyone.
     * `$offer` is what the reader pays today: `offer_price` and the automatic `discount` it applies (null for none).
     *
     * @param list<array{server: Server, inbounds: Collection<int, ServerInbound>}> $choices
     * @return array<string, mixed>
     */
    public static function present(Plan $plan, ?PlanCategory $category, array $choices, Buyer $buyer, Quote $offer): array
    {
        $discount = $offer->automatic;

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'checkout_note' => $plan->checkout_note,
            'price' => $plan->price,
            'first_purchase_price' => $buyer->firstPurchase ? $plan->firstPurchasePrice() : null,
            'agent_price' => $buyer->agent ? $plan->agentPrice() : null,
            'offer_price' => $offer->amount(),
            'discount' => $discount === null ? null : [
                'source' => $discount->source->value,
                'label' => $discount->label,
                'percent' => $discount->percent,
                'ends_at' => DiscountForm::moment($discount->endsAt),
            ],
            'traffic_gb' => $plan->traffic_gb,
            'duration_days' => ServiceTerms::daysOf($plan->duration_hours),
            'duration_hours' => $plan->duration_hours,
            'devices' => $plan->ip_limit,
            'category_id' => $category?->id,
            'locations' => array_map(static fn(array $choice): array => ['id' => $choice['server']->id, 'name' => $choice['server']->name], $choices),
        ];
    }

    /**
     * A catalogue category; null for the group of the rest («سایر پلن‌ها»).
     *
     * @return array{id: int, name: string}|null
     */
    public static function category(?PlanCategory $category): ?array
    {
        return $category === null ? null : ['id' => $category->id, 'name' => $category->name];
    }
}
