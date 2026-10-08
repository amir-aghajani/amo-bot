<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTO;

/**
 * What a request of the website that orders is known by (OrderService::open*()): its key — the Idempotency-Key, one a
 * customer — and the way to pay it asks for, by the method's id. A key is one checkout attempt and its way to pay part of
 * it: made again with another, it is no request made again but another one under its key (refused, a 422).
 */
final class OrderKey
{
    public function __construct(
        public readonly string $key,
        public readonly int $method,
    ) {}
}
