<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\DTO\AutomaticDiscounts;
use App\Modules\Discounts\DTO\CodeTarget;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Discounts\Services\Discounts;
use App\Modules\Orders\DTO\Buyer;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\Models\Server;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Models\User;
use App\Support\Money;

/**
 * The one pricing of what a customer buys for themselves, used everywhere (the bot, the website, OrderService,
 * automatic renewal). Three steps:
 * 1. the base: the plan's price for this customer (buyer(): its price, first-purchase or agent price, the lowest that is
 *    theirs), or an extra's price as offered;
 * 2. the largest automatic discount (a sale, their own percent, their tier's — never added together);
 * 3. the code they typed, applied to what step 2 left (DiscountCodes::apply()).
 * Whole Toman, never below zero. Wallet top-ups and agents' traffic are never discounted.
 */
final class Pricing
{
    public function __construct(
        private readonly Discounts $discounts,
        private readonly DiscountCodes $codes,
    ) {}

    /**
     * Which of these plans' special prices the customer may pay, read once for a list and only when one of the plans has
     * such a price. A visitor who has not signed in (null) has bought nothing and pays no agent price.
     */
    public function buyer(?User $user, Plan ...$plans): Buyer
    {
        $first = false;
        $agent = false;
        foreach ($plans as $plan) {
            $first = $first || $plan->firstPurchasePrice() !== null;
            $agent = $agent || $plan->agentPrice() !== null;
        }

        return new Buyer(
            firstPurchase: $first && ($user === null || !Order::boughtBy($user->id)->exists()),
            agent: $agent && $user !== null && $user->paysAgentPrices(),
        );
    }

    /** The customer's automatic discounts, read once for any number of prices. */
    public function automatic(?User $user): AutomaticDiscounts
    {
        return $this->discounts->for($user);
    }

    /**
     * The purchase price of each plan as the customer browses them (no code), the customer read once for the list.
     *
     * @return array<int, Quote> By plan id
     */
    public function offers(?User $user, Plan ...$plans): array
    {
        $buyer = $this->buyer($user, ...$plans);
        $automatic = $this->automatic($user);
        $quotes = [];
        foreach ($plans as $plan) {
            $quotes[$plan->id] = self::offer($buyer, $automatic, $plan);
        }

        return $quotes;
    }

    /** The plan's purchase price for a customer already read: their base less the largest automatic discount. */
    public static function offer(Buyer $buyer, AutomaticDiscounts $automatic, Plan $plan): Quote
    {
        $price = $buyer->purchase($plan);

        return new Quote($price->amount, $price->pricing, $automatic->best($plan->id, OrderType::Purchase, $price->amount));
    }

    /**
     * The customer's purchase of the plan (on `$server` once picked), with the code they typed.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function purchase(?User $user, Plan $plan, ?Server $server = null, ?string $code = null): Quote
    {
        $quote = $this->offers($user, $plan)[$plan->id];

        return $user === null || $code === null ? $quote : $this->coded($quote, $user, $code, new CodeTarget(OrderType::Purchase, $plan->id, $server?->id));
    }

    /**
     * A renewal of the customer's service on the plan, with the code they typed (automatic renewal passes none).
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function renewal(User $user, Subscription $subscription, Plan $plan, ?string $code = null): Quote
    {
        $price = $this->buyer($user, $plan)->renewal($plan);
        $quote = new Quote($price->amount, $price->pricing, $this->automatic($user)->best($plan->id, OrderType::Renewal, $price->amount));

        return $code === null ? $quote : $this->coded($quote, $user, $code, new CodeTarget(OrderType::Renewal, $plan->id, $subscription->server_id));
    }

    /**
     * Extra traffic or time for the customer's service at `$base` (CustomerExtras::quote()), with the code they typed.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function extra(User $user, Subscription $subscription, ExtraKind $kind, string $base, ?string $code = null): Quote
    {
        $quote = new Quote(Money::normalize($base), OrderPricing::Regular, $this->automatic($user)->best($subscription->plan_id, $kind->orderType(), $base));

        return $code === null ? $quote : $this->coded($quote, $user, $code, new CodeTarget($kind->orderType(), $subscription->plan_id, $subscription->server_id));
    }

    /**
     * The quote with the code applied to what the automatic discount left.
     *
     * @throws DiscountCodeRefusedException
     */
    private function coded(Quote $quote, User $user, string $code, CodeTarget $target): Quote
    {
        return $quote->withCode($this->codes->apply($user, $code, $target, $quote->afterAutomatic()));
    }
}
