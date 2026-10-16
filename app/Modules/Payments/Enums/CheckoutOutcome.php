<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What came of a checkout (Services\Checkout::pay()): the first four are success (the website's `outcome`), the rest why nothing was paid. */
enum CheckoutOutcome: string
{
    /** Paid at once — the wallet —: the order as it ended, delivered or failed (support retries it); refunded since. */
    case Settled = 'settled';

    /** A card to transfer to: the payment waits for its receipt — or has it, with support. */
    case Transfer = 'transfer';

    /** An online gateway's page to pay on: the payment waits for the gateway to say it was paid. */
    case Link = 'link';

    /** Paid, and its delivery under way. */
    case Processing = 'processing';

    /** The order was paid or closed elsewhere in the same moment — or the caller's own guard refused it —: nothing charged. */
    case Closed = 'closed';

    /** A website request made again with its key found its order cancelled since (by support, or expired): nothing charged. */
    case Cancelled = 'cancelled';

    /** The wallet cannot cover it (an agent's credit counting): nothing ordered. */
    case Short = 'short';

    /** The gateway said no as it was charged (the wallet spent meanwhile): the payment failed with why, the order still open. */
    case Refused = 'refused';
}
