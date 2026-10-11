<?php

declare(strict_types=1);

namespace App\Modules\Rewards\DTO;

use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A reward given (Services\Rewards::give()) and where it went: the service that got traffic or days, or — a plan — the
 * order and the payment that gave the service (delivered, or failed and waiting for support's retry).
 */
final readonly class Given
{
    public function __construct(
        public Reward $reward,
        public ?Subscription $subscription = null,
        public ?Order $order = null,
        public ?Payment $payment = null,
    ) {}
}
