<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTO;

use App\Support\Money;

/**
 * What a service the customer builds costs before any discount (Services\CustomServices::quote()): its gigabytes and its
 * days, each priced whole at its tier's rate (PriceTiers::priceOf()), the two added.
 */
final readonly class CustomPrice
{
    public function __construct(
        public int $gb,
        public int $days,
        /** The gigabytes' price, Toman */
        public string $traffic,
        /** The days' price, Toman */
        public string $time,
    ) {}

    /** The two prices added: the base a customer's discounts come off. */
    public function total(): string
    {
        return Money::add($this->traffic, $this->time);
    }
}
