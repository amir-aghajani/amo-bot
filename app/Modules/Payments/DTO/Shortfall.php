<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * A wallet that cannot pay a price (Payments\Services\Checkout::shortfall()): its balance — below zero an agent's debt —,
 * the price, and what is missing: the price less what the wallet can pay (an agent's credit counting), the whole price
 * when it can pay nothing. Toman, as decimal strings.
 */
final class Shortfall
{
    public function __construct(
        public readonly string $balance,
        public readonly string $price,
        public readonly string $missing,
    ) {}
}
