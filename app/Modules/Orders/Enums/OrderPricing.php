<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * Which of a plan's prices a purchase or a renewal was made at (`orders.pricing`, see Services\Pricing): the plan's own,
 * its first-purchase price, its agent price, or none at all — a service given free as a prize or a gift
 * (Rewards\Services\Rewards). Every other kind of order is `regular`.
 */
enum OrderPricing: string
{
    case Regular = 'regular';
    case FirstPurchase = 'first_purchase';
    case Agent = 'agent';
    case Gift = 'gift';

    /** The words the report group says beside what was paid; null for the plan's own price. */
    public function label(): ?string
    {
        return match ($this) {
            self::Regular => null,
            self::FirstPurchase => 'قیمت خرید اول',
            self::Agent => 'قیمت نماینده',
            self::Gift => 'هدیه',
        };
    }
}
