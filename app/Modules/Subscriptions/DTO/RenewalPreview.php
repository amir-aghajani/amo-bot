<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A renewal before it is paid (Services\CustomerRenewal::preview()), shown by the bot's and the website's checkout: the
 * plan, today's price and its quote (the price for the customer less the automatic discount, with the lines saying why),
 * and the service as the renewal would leave it — a copy of its row, nothing saved, no panel asked.
 */
final class RenewalPreview
{
    public function __construct(
        public readonly Plan $plan,
        public readonly string $price,
        public readonly Subscription $after,
        public readonly Quote $quote,
    ) {}
}
