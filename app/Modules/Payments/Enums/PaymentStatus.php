<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';                 // created, user has not paid yet
    case AwaitingReview = 'awaiting_review';  // manual gateway: receipt submitted, admin must approve
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /** @return list<self> Not settled either way: the money is not in, and a refused receipt may still be approved by hand. */
    public static function open(): array
    {
        return [self::Pending, self::AwaitingReview, self::Failed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
