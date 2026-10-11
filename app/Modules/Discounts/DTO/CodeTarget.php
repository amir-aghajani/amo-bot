<?php

declare(strict_types=1);

namespace App\Modules\Discounts\DTO;

use App\Modules\Orders\Enums\OrderType;

/**
 * The order a discount code is judged for (Services\DiscountCodes::apply()): its kind, its plan and its server — for a
 * renewal or an extra, the service's plan (null for a service with no plan) and server.
 */
final readonly class CodeTarget
{
    public function __construct(
        public OrderType $kind,
        public ?int $planId,
        public ?int $serverId,
    ) {}
}
