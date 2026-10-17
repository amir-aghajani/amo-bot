<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\DTO\CustomPrice;
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
 *    theirs), an extra's price as offered, or a package the customer builds by its tiers (bought: custom(); a service
 *    renewed with one: renewal());
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

        return self::quoted($automatic, $price->amount, $price->pricing, OrderType::Purchase, $plan->id);
    }

    /**
     * The customer's purchase of the plan (on `$server` once picked), with the code they typed.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function purchase(?User $user, Plan $plan, ?Server $server = null, ?string $code = null): Quote
    {
        return $this->coded($this->offers($user, $plan)[$plan->id], $user, $code, new CodeTarget(OrderType::Purchase, $plan->id, $server?->id));
    }

    /**
     * What renewing the customer's service on each plan costs them (no code): their renewal price for it (never a first
     * purchase's) less the largest automatic discount — the customer read once for the list, as offers() reads them.
     *
     * @return array<int, Quote> By plan id
     */
    public function renewalOffers(User $user, Plan ...$plans): array
    {
        $buyer = $this->buyer($user, ...$plans);
        $automatic = $this->automatic($user);
        $quotes = [];
        foreach ($plans as $plan) {
            $price = $buyer->renewal($plan);
            $quotes[$plan->id] = self::quoted($automatic, $price->amount, $price->pricing, OrderType::Renewal, $plan->id);
        }

        return $quotes;
    }

    /**
     * A renewal of the customer's service with the code they typed (automatic renewal passes none): on a plan, at their
     * renewal price for it (renewalOffers()); or with a package they build at `$with`'s price (CustomServices::quote()),
     * priced as built() prices one.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function renewal(User $user, Subscription $subscription, Plan|CustomPrice $with, ?string $code = null): Quote
    {
        if ($with instanceof CustomPrice) {
            return $this->built($user, $with, OrderType::Renewal, $subscription->server_id, $code);
        }

        return $this->coded($this->renewalOffers($user, $with)[$with->id], $user, $code, new CodeTarget(OrderType::Renewal, $with->id, $subscription->server_id));
    }

    /**
     * Extra traffic, time or devices for the customer's service at `$base` (CustomerExtras::quote()), with the code
     * they typed.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function extra(User $user, Subscription $subscription, ExtraKind $kind, string $base, ?string $code = null): Quote
    {
        return $this->coded(self::extraOffer($this->automatic($user), $subscription, $kind, $base), $user, $code, new CodeTarget($kind->orderType(), $subscription->plan_id, $subscription->server_id));
    }

    /**
     * An extra's price at `$base` for a customer already read, as they browse its amounts (no code): less the largest
     * automatic discount for its service's plan — what offer() is to a plan.
     */
    public static function extraOffer(AutomaticDiscounts $automatic, Subscription $subscription, ExtraKind $kind, string $base): Quote
    {
        return self::quoted($automatic, $base, OrderPricing::Regular, $kind->orderType(), $subscription->plan_id);
    }

    /**
     * A service the customer builds at `$price` (CustomServices::quote(): its GB and days by their tiers), on `$server`
     * once picked, with the code they typed — a purchase priced as built() prices one. A visitor (null) takes no code.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    public function custom(?User $user, CustomPrice $price, ?Server $server = null, ?string $code = null): Quote
    {
        return $this->built($user, $price, OrderType::Purchase, $server?->id, $code);
    }

    /**
     * A package the customer builds, bought as `$type` (a purchase, or a renewal of their service on server `$serverId`)
     * at `$price`, with the code they typed — an order of no plan, as an extra of a service without one is priced: no
     * first-purchase or agent price (a plan's), only a sale of every plan reaches it, and a code held to plans never does.
     *
     * @throws DiscountCodeRefusedException the code does not hold for it
     */
    private function built(?User $user, CustomPrice $price, OrderType $type, ?int $serverId, ?string $code): Quote
    {
        $quote = self::quoted($this->automatic($user), $price->total(), OrderPricing::Regular, $type, null);

        return $this->coded($quote, $user, $code, new CodeTarget($type, null, $serverId));
    }

    /** `$base` at `$pricing`, less the largest of the customer's automatic discounts for an order of `$type` on plan `$planId`. */
    private static function quoted(AutomaticDiscounts $automatic, string $base, OrderPricing $pricing, OrderType $type, ?int $planId): Quote
    {
        return new Quote(Money::normalize($base), $pricing, $automatic->best($planId, $type, $base));
    }

    /**
     * The quote with the code the customer typed applied to what the automatic discount left; as it is without one, or
     * for a visitor (null), who takes none.
     *
     * @throws DiscountCodeRefusedException
     */
    private function coded(Quote $quote, ?User $user, ?string $code, CodeTarget $target): Quote
    {
        return $user === null || $code === null ? $quote : $quote->withCode($this->codes->apply($user, $code, $target, $quote->afterAutomatic()));
    }
}
