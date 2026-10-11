<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTO;

/**
 * A website ordering request's identity: its Idempotency-Key and the payment method it asked for. A key is one checkout
 * attempt including its way to pay, so the same key with another method is refused (RequestKeyReusedException).
 */
final class OrderKey
{
    public function __construct(
        public readonly string $key,
        public readonly int $method,
    ) {}
}
