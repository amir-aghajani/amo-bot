<?php

declare(strict_types=1);

namespace App\Modules\Agency\Enums;

/** What moved an agent's traffic (one line of `traffic_transactions`, Agency\Services\TrafficPool). */
enum TrafficTransactionType: string
{
    /** Bought from the main bot: a traffic order delivered. */
    case Purchase = 'purchase';
    /** Drawn by a purchase their bot delivered. */
    case Sale = 'sale';
    /** Drawn by a renewal their bot delivered. */
    case Renewal = 'renewal';
    /** Drawn by the traffic the shop gave one of their bot's services from its subscriptions screen («افزایش زمان و حجم»). */
    case Extension = 'extension';
    /**
     * Given back: what a failed delivery or extension drew, or — out of the pool — what a refunded traffic purchase had
     * added.
     */
    case Refund = 'refund';
    /** Set right by the shop, either way. */
    case Adjust = 'adjust';
}
