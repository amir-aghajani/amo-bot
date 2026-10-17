<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** Where an order stands: pending → paid → processing → fulfilled | failed, or cancelled unpaid, or refunded. */
enum OrderStatus: string
{
    case Pending = 'pending';       // made, waiting for a payment
    case Paid = 'paid';             // paid, delivery not started
    case Processing = 'processing'; // claimed by the process delivering it («در حال ساخت»); stale once its delivery stops touching it (OrderService::STALE_PROCESSING_MINUTES)
    case Fulfilled = 'fulfilled';   // delivered: a service made or changed, the wallet credited, an agent's traffic added
    case Cancelled = 'cancelled';   // dropped unpaid — by support, or expired
    case Failed = 'failed';         // paid, but the delivery failed; waits for support's retry
    case Refunded = 'refunded';     // the payment was given back
}
