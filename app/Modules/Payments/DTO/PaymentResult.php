<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/** A gateway's verdict on a payment: yes (with its own reference, if any), or no with why. */
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
