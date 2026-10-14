<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\DTO;

use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Catalog\DTO\Package;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A renewal before it is paid (Services\CustomerRenewal::preview()), shown by the bot's and the website's checkout: what
 * it renews with — a plan, or a package the customer builds at its price before discounts — and what that gives, the
 * service as the renewal would leave it by `$method` — a copy of its row, nothing saved, no panel asked —, today's price
 * as its quote (the price for the customer less the automatic discount, with the lines saying why; what is paid is its
 * amount()), and the methods the shop offers to pick among.
 */
final class RenewalPreview
{
    /** @param non-empty-list<RenewalMethod> $methods */
    public function __construct(
        public readonly Plan|CustomPrice $with,
        public readonly Package $package,
        public readonly Subscription $after,
        public readonly Quote $quote,
        public readonly RenewalMethod $method,
        public readonly array $methods,
    ) {}
}
