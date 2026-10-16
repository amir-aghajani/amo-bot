<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

use App\Modules\Payments\Enums\OnlineState;

/**
 * An online gateway's word on a payment: paid (with its own reference, if any), waiting, or closed. Waiting with money
 * in (`moneyIn`): some of it came, or a receipt is in review at the gateway — the shop never gives up on it by itself.
 */
final class OnlineVerdict
{
    private function __construct(
        public readonly OnlineState $state,
        public readonly ?string $reference = null,
        public readonly bool $moneyIn = false,
    ) {}

    public static function paid(?string $reference = null): self
    {
        return new self(OnlineState::Paid, $reference);
    }

    public static function waiting(bool $moneyIn = false): self
    {
        return new self(OnlineState::Waiting, moneyIn: $moneyIn);
    }

    public static function closed(): self
    {
        return new self(OnlineState::Closed);
    }
}
