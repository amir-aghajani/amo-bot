<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * A wallet that cannot pay a price (Services\Checkout::shortfall()): its balance (negative: a debt), the price, and what
 * is missing — what a top-up must bring, a debt included, an agent's credit counting. Toman, as decimal strings.
 */
final class Shortfall
{
    public function __construct(
        public readonly string $balance,
        public readonly string $price,
        public readonly string $missing,
    ) {}
}
