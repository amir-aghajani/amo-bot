<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTO;

use App\Modules\Orders\Enums\OrderPricing;

/** What a customer pays for a plan, and which of the plan's prices that is (Buyer). */
final readonly class PlanPrice
{
    public function __construct(
        /** Toman, normalised */
        public string $amount,
        public OrderPricing $pricing,
    ) {}
}
