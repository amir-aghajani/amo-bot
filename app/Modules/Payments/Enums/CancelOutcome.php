<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What PaymentService::cancel() (or cancelWithOrder()) came to. */
enum CancelOutcome
{
    /** Decided otherwise in the same moment: nothing changed. */
    case Lost;
    /**
     * Cancelled; its order stays as it is — paid another way, closed already, or (the payments screen's cancel) waiting on
     * another payment's receipt in review.
     */
    case Alone;
    /** Cancelled, and its open order with it — and the order's other open payments. */
    case WithOrder;
}
