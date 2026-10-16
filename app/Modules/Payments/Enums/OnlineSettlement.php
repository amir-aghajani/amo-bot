<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What settling a payment an online gateway said was paid came to (Services\PaymentService::settleOnline()). */
enum OnlineSettlement: string
{
    /** It paid its order, which is being delivered. */
    case Settled = 'settled';
    /** Its order had closed meanwhile: the money went to the customer's wallet. */
    case Credited = 'credited';
    /** It was paid already — another call came first —: nothing done. */
    case Decided = 'decided';
}
