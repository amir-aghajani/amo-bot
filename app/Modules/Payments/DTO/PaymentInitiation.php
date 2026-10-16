<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * What the customer does next to pay a payment: nothing (it settles at once), pay to the card it was shown — or to the
 * one support gives them in their private chat (`transfer`) —, or pay on an online gateway's page (`link`).
 */
final class PaymentInitiation
{
    private function __construct(
        public readonly ?CardTransfer $transfer,
        public readonly ?PayLink $link = null,
    ) {}

    public static function instant(): self
    {
        return new self(null);
    }

    public static function transfer(CardTransfer $transfer): self
    {
        return new self($transfer);
    }

    public static function link(PayLink $link): self
    {
        return new self(null, $link);
    }
}
