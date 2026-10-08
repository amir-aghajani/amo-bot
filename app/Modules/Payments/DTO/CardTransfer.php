<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/** Where a card-to-card payment goes: the card's number (bare digits), whose card it is, and the method's note to the customer. */
final class CardTransfer
{
    public function __construct(
        public readonly string $card,
        public readonly string $holder,
        public readonly string $instructions,
    ) {}
}
