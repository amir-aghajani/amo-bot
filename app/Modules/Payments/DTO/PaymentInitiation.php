<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * What the customer does next to pay a payment: nothing (it settles at once), or pay to the card it was shown — or to
 * the one support gives them in their private chat (`transfer`).
 */
final class PaymentInitiation
{
    private function __construct(public readonly ?CardTransfer $transfer) {}

    public static function instant(): self
    {
        return new self(null);
    }

    public static function transfer(CardTransfer $transfer): self
    {
        return new self($transfer);
    }
}
