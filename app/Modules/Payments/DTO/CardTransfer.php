<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * Where a card-to-card payment goes: the card it was shown (Models\PaymentCard) — its number (bare digits), whose card it
 * is and the owner's word for it (`bank`, null for none) —, or none for a payment taken in support's private chat
 * (toSupport()), where support gives the customer a card; and the method's note to the customer.
 */
final class CardTransfer
{
    public function __construct(
        public readonly ?string $card,
        public readonly ?string $holder,
        public readonly string $instructions,
        public readonly ?string $bank = null,
    ) {}

    /** A payment shown no card: the shop took it in support's private chat (Services\PaymentCards::toSupport()). */
    public static function toSupport(string $instructions): self
    {
        return new self(null, null, $instructions);
    }
}
