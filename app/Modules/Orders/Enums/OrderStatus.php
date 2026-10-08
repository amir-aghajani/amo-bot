<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';       // created, waiting for payment
    case Paid = 'paid';             // payment confirmed, delivery not started
    case Processing = 'processing'; // claimed by the process delivering it («در حال ساخت»); stale after ten minutes
    case Fulfilled = 'fulfilled';   // config delivered / wallet credited
    case Cancelled = 'cancelled';
    case Failed = 'failed';         // paid but provisioning failed; needs admin attention
    case Refunded = 'refunded';     // the payment went back to the wallet
}
