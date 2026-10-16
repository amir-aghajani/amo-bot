<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * Where an online gateway's payment is paid: its page (`url`) until `expiresAt` (the gateway's end, or the time the shop
 * keeps its page when the gateway gives none — known once the page is open), the exact sum the page asks (`amount`: a
 * gateway may add a few Toman of its own to tell transfers apart — the payment's own amount otherwise), when the gateway
 * gave one to show in the bot, the card that sum goes to (`card`), and of that sum what the gateway adds for itself —
 * the fee the shop passes on, a fee the gateway's merchant set on the buyer, its markup (`fee`; null for none).
 */
final class PayLink
{
    public function __construct(
        public readonly string $url,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly string $amount,
        public readonly ?CardTransfer $card = null,
        public readonly ?string $fee = null,
    ) {}
}
