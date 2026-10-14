<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTO;

use App\Support\Money;

/**
 * A price by tiers («قیمت‌گذاری پلکانی», the custom builder's for each GB and each day): each tier from a quantity on, at
 * its price per unit. A quantity is priced whole at the rate of the tier it falls into — 60 GB with tiers from 1 at
 * 3,000 and from 50 at 2,500 costs 60 × 2,500.
 */
final readonly class PriceTiers
{
    /** @param list<array{from: int, price: string}> $tiers The smallest `from` first, each once; prices Money strings */
    public function __construct(public array $tiers) {}

    /** Whether it prices anything. */
    public function sells(): bool
    {
        return $this->tiers !== [];
    }

    /** The price of one unit at `$quantity`: its tier's; null below the first tier. */
    public function unitPrice(int $quantity): ?string
    {
        $price = null;
        foreach ($this->tiers as $tier) {
            if ($quantity < $tier['from']) {
                break;
            }
            $price = $tier['price'];
        }

        return $price;
    }

    /** What `$quantity` costs whole, at its tier's unit price; null when no tier prices it. */
    public function priceOf(int $quantity): ?string
    {
        $unit = $quantity > 0 ? $this->unitPrice($quantity) : null;

        return $unit === null ? null : Money::times($unit, $quantity);
    }
}
