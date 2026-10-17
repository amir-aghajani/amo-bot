<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** Where a payment attempt stands. Every move between them is a compare-and-swap (PaymentService). */
enum PaymentStatus: string
{
    case Pending = 'pending';                 // started, not paid yet (a card transfer: no receipt yet)
    case AwaitingReview = 'awaiting_review';  // a card transfer's receipt waits for support
    case Paid = 'paid';
    case Failed = 'failed';                   // refused: a rejected receipt, or the gateway said no
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /** @return list<self> Not settled: the money is not in, and even a refused receipt may still be approved by hand. */
    public static function open(): array
    {
        return [self::Pending, self::AwaitingReview, self::Failed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** Paid — or refunded since —: the money came in, and its gateway has nothing more to say of it. */
    public function isSettled(): bool
    {
        return $this === self::Paid || $this === self::Refunded;
    }
}
