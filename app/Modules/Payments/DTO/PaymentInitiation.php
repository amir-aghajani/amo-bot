<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/** What the customer does next after picking a method: nothing (it settles at once), or pay to a card (`transfer`). */
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

    public function isInstant(): bool
    {
        return $this->transfer === null;
    }
}
