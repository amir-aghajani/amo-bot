<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * What the customer does next once they picked how to pay: nothing — an instant gateway (the wallet) settles the payment
 * at once —, or pay to a card and send the receipt (`transfer`, which the bot words: BotText::CardInstructions).
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

    public function isInstant(): bool
    {
        return $this->transfer === null;
    }
}
