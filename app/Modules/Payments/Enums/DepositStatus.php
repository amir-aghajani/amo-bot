<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What came of a bank SMS a phone app forwarded (Deposits). */
enum DepositStatus: string
{
    /** Taken, its payment not looked for yet (a moment, or a crash midway). */
    case Received = 'received';
    /** It approved the one open card payment that asked its sum. */
    case Matched = 'matched';
    /**
     * A deposit no open card payment asks, one its source could not vouch for (ForApp did not recognise it as a bank's), or
     * one attached to a payment by hand that could not pay it: support's to look at.
     */
    case Unmatched = 'unmatched';
    /** A deposit more than one open card payment asks: none approved, support's to decide. */
    case Ambiguous = 'ambiguous';
    /** No deposit: a withdrawal, an SMS whose sum was not read, or older than the shop listens back. */
    case Ignored = 'ignored';
    /** The app's own test: the connection works. */
    case Test = 'test';
}
