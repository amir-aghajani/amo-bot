<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTO;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderPricing;
use App\Support\Money;

/**
 * Which of a plan's prices a customer may pay (Services\Pricing::buyer()): its first-purchase price (they have bought
 * nothing yet) and its agent price (an agent, or a customer of a group with agent prices). They pay the lowest that is
 * theirs; on a tie between the two, the agent price, so no first purchase is spent on it.
 */
final readonly class Buyer
{
    public function __construct(
        public bool $firstPurchase,
        public bool $agent,
    ) {}

    /** What they pay to buy the plan. */
    public function purchase(Plan $plan): PlanPrice
    {
        $price = $this->renewal($plan);
        $first = $this->firstPurchase ? $plan->firstPurchasePrice() : null;

        return $first !== null && Money::compare($first, $price->amount) < 0 ? new PlanPrice($first, OrderPricing::FirstPurchase) : $price;
    }

    /** What they pay to renew a service of the plan: a renewal is never a first purchase. */
    public function renewal(Plan $plan): PlanPrice
    {
        $agent = $this->agent ? $plan->agentPrice() : null;

        return $agent !== null ? new PlanPrice($agent, OrderPricing::Agent) : new PlanPrice(Money::normalize($plan->price), OrderPricing::Regular);
    }
}
