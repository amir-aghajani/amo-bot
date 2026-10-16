<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What an online gateway says of a payment it was asked about (Contracts\OnlineGateway::check()). */
enum OnlineState: string
{
    /** The money came in. */
    case Paid = 'paid';
    /** Nothing yet, and its page still takes money. */
    case Waiting = 'waiting';
    /** Its page closed — its time ran out, or the gateway failed it —, nothing paid. */
    case Closed = 'closed';
}
