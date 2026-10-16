<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Modules\Payments\DTO\OnlineInvoice;
use App\Modules\Payments\DTO\OnlineRequest;
use App\Modules\Payments\DTO\OnlineVerdict;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Models\Payment;

/**
 * The gateway of an online method (Enums\GatewayKind::Online): the customer pays on its page. Both calls talk to the
 * gateway, so neither runs inside a transaction; a payment keeps one page (Services\OnlineInvoices) and what came of it
 * is asked as often as needed (Services\OnlinePayments) — a callback only says to ask. initiate() reads what open()
 * gave, kept on the payment, and calls nothing.
 */
interface OnlineGateway extends GatewayInterface
{
    /**
     * Opens `$payment` on the gateway as `$request` says. Two openings of one payment at once may each make an invoice
     * at the gateway: the shop keeps the first saved, and the other lapses unpaid.
     *
     * @throws GatewayUnavailableException the gateway is out of reach, or refused to open it
     */
    public function open(Payment $payment, OnlineRequest $request): OnlineInvoice;

    /**
     * What came of an opened payment, as the gateway says it now — never as a callback says.
     *
     * @throws GatewayUnavailableException the gateway is out of reach, or answered nothing it means
     */
    public function check(Payment $payment): OnlineVerdict;
}
