<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;

/** The gateway of one payment method row, made by its driver from the row's settings (GatewayRegistry::forMethod()). */
interface GatewayInterface
{
    /**
     * What the customer does next to pay `$payment`, made with this method: nothing (it settles at once), or pay to the
     * card it was shown and send the receipt.
     */
    public function initiate(Payment $payment): PaymentInitiation;

    /**
     * The gateway's part of settling a payment, inside the settlement's transaction (the wallet's debit, which rolls back
     * with it). Database work only: anything that calls another service must happen before the transaction opens — which
     * is tried again on a deadlock (Core\Database\Ledger::ATTEMPTS), so this may run more than once for one settlement.
     */
    public function settle(Payment $payment): PaymentResult;
}
