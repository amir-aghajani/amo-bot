<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\Payment;

/**
 * A payment's move lost to another process (support and the auto-approve timer at once). Thrown inside the settlement's
 * transaction so the gateway's work rolls back; PaymentService answers it as "not this call".
 */
final class PaymentSettledException extends DomainRuleException
{
    public function __construct(Payment $payment)
    {
        parent::__construct("پرداخت #{$payment->id} همین حالا به شکل دیگری تعیین تکلیف شد.");
    }
}
