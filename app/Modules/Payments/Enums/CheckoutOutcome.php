<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * What came of a checkout (Payments\Services\Checkout::pay()). The first three are what a door shows as done — the
 * website's API says them as they are (`outcome`) —; the rest are why nothing was paid.
 */
enum CheckoutOutcome: string
{
    /** Paid at once — the wallet —: the order as it ended, delivered or failed (support retries it); refunded since. */
    case Settled = 'settled';

    /** A card to transfer to: the payment waits for its receipt — or has it, with support. */
    case Transfer = 'transfer';

    /** Paid, and its delivery under way. */
    case Processing = 'processing';

    /** The order was paid or closed elsewhere in the same moment — or the door's own hold refused it —: nothing charged. */
    case Closed = 'closed';

    /**
     * A request made again with its key (the website's) found the order it came to cancelled since — by support, or left
     * unpaid past its time, maybe days ago —: nothing charged; buying it is another request.
     */
    case Cancelled = 'cancelled';

    /** The wallet cannot cover it (an agent's credit counting): nothing ordered. */
    case Short = 'short';

    /** The gateway said no as it was charged (the wallet spent meanwhile): the payment failed with why, the order still open. */
    case Refused = 'refused';
}
