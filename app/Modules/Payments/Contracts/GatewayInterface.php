<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;

/** The gateway of one payment method row, made by its driver from the row's settings (GatewayRegistry::forMethod()). */
interface GatewayInterface
{
    /** What the customer does next once they picked this method: nothing (it settles at once), or pay to a card and send the receipt. */
    public function initiate(): PaymentInitiation;

    /**
     * The gateway's part of settling a payment, inside the settlement's transaction (the wallet's debit, which rolls back
     * with it). Database work only: anything that calls another service must happen before the transaction opens.
     */
    public function settle(Payment $payment): PaymentResult;
}
