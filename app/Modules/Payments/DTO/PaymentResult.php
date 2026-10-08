<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * Outcome of verifying a payment with its gateway: yes, with the gateway's own id of the payment when it has one, or
 * no, with why.
 */
final class PaymentResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $reference = null,
        public readonly ?string $message = null,
    ) {}

    public static function success(?string $reference = null): self
    {
        return new self(true, $reference);
    }

    public static function failure(string $message): self
    {
        return new self(false, null, $message);
    }
}
