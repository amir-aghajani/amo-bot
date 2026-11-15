<?php

declare(strict_types=1);

namespace App\Modules\Payments\Deposits\Enums;

/** What came of a deposit attached by hand to a payment it names (Services\Deposits::attach()). */
enum AttachOutcome: string
{
    /** It approved the payment. */
    case Approved = 'approved';
    /** It had approved that payment before: the same deposit sent again. */
    case Confirmed = 'confirmed';
    /**
     * It confirms what support (or the review window) approved before and no deposit named yet: the same money, now on
     * record — as a deposit that finds such a payment by its sum does.
     */
    case Confirms = 'confirms';
    /** Another request is deciding it this moment: ask again shortly. */
    case Busy = 'busy';
    /** No card payment of the shop has that number. */
    case NoPayment = 'no_payment';
    /** The payment, or its order, is paid already — or the deposit approved another payment: never paid twice. */
    case Paid = 'paid';
    /** The payment, or its order, was cancelled or expired. */
    case Closed = 'closed';
    /** Less than its order's own price: kept, approving nothing. */
    case TooLittle = 'too_little';

    /** Whether the payment it names is paid by the deposit now. */
    public function paid(): bool
    {
        return $this === self::Approved || $this === self::Confirmed || $this === self::Confirms;
    }
}
