<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Enums;

/**
 * Why a service has no renewal checkout (Services\CustomerRenewal::refusal()), in the order it is asked — the bot and
 * the website only say it in their own words.
 */
enum RenewalRefusal
{
    /** The renewal method named is not one the shop offers. */
    case MethodNotOffered;

    /** A service renewed on its own plan was named a package to build. */
    case RenewsOnItsPlan;

    /** A plan was named that the service may not be renewed on: another than its own, or one not among its choices. */
    case PlanNotOffered;

    /**
     * It may not be renewed now: switched off or gone from its panel, its plan giving nothing or an agent's traffic not
     * covering it — or, without a plan, a free test or nothing to pick (no plan on sale on its server, the builder off).
     */
    case NotRenewable;

    /** Its renewal window is closed: CustomerRenewal::window() says from when. */
    case NotYet;

    /** A renewal of it is under way: its receipt with support, paid and being delivered, or failed (OrderService::renewalUnderWay()). */
    case UnderWay;

    /** A package was named while the builder is not offered. */
    case BuilderOff;

    /** A service without a plan, nothing picked: a plan among its choices — the builder is not offered. */
    case PickAPlan;

    /** A service without a plan, nothing picked: a plan among its choices, or a package while it is buildable. */
    case PickAPlanOrPackage;

    /** Whether the service may be renewed now on what its customer picks: nothing but the pick stands in the way. */
    public function picking(): bool
    {
        return $this === self::PickAPlan || $this === self::PickAPlanOrPackage;
    }
}
