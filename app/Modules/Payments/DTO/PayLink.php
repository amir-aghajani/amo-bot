<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * Where an online gateway's payment is paid: its page (`url`) until `expiresAt` (the gateway's end, or the time the shop
 * keeps its page when the gateway gives none — known once the page is open), the sum to pay in Toman (`amount`: the
 * payment's own, or more when the gateway says up front it adds a few Toman to tell transfers apart; a page may still
 * ask a few Toman more of its own — Variza's — or the same in a coin — Plisio's), when the gateway
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
