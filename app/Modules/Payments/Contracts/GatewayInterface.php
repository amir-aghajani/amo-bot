<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;

/**
 * The gateway of one payment method row — the card, the merchant account it works for —, made by its driver
 * (GatewayDriver::gateway(), through `GatewayRegistry::forMethod()`) from the row's settings.
 */
interface GatewayInterface
{
    /** What the customer does next once they picked this method: nothing (it settles at once), or pay to a card and send the receipt. */
    public function initiate(): PaymentInitiation;

    /**
     * The gateway's own part of settling a payment, inside the settlement's transaction (PaymentService): the wallet's
     * debit, which unwinds with that transaction when another decision won the payment or its order; nothing for a card
     * transfer a person approved. Database work only — a gateway that must ask another service does that before the
     * settlement starts, never with a transaction open.
     */
    public function settle(Payment $payment): PaymentResult;
}
