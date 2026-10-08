<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\Payment;

/**
 * The payment's move lost to another process — the admin and the auto-approve timer at the same moment. Thrown inside
 * PaymentService's settlement transaction, unwinding it takes the gateway's work (a wallet debit) back with it;
 * PaymentService answers the loss as "not this call" (false), and the decision that stood is someone else's.
 */
final class PaymentSettledException extends DomainRuleException
{
    public function __construct(Payment $payment)
    {
        parent::__construct("پرداخت #{$payment->id} همین حالا به شکل دیگری تعیین تکلیف شد.");
    }
}
