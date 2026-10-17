<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers;

use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\DTO\PayLink;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;

/**
 * What every online gateway (Contracts\OnlineGateway) does alike with what its open() kept on a payment (Services\
 * OnlineInvoices): initiate() shows that page — at the payment's own sum, unless the gateway's details say the page asks
 * another —, nothing to pay on for a payment the gateway would not open; and settle() answers yes with the payment's
 * reference, the gateway having said it was paid before it runs (Services\OnlinePayments).
 */
trait OnlinePage
{
    /** Its page, and the payment's own sum. */
    public function initiate(Payment $payment): PaymentInitiation
    {
        return $this->page($payment);
    }

    /** The gateway said it was paid before this runs: its latest id of it, kept as the payment's reference, settles it. */
    public function settle(Payment $payment): PaymentResult
    {
        return PaymentResult::success($payment->reference);
    }

    /**
     * The page open() kept and until when it takes money, the sum to pay in Toman (`$amount`: the payment's own when null),
     * the card the gateway gave to show and, of the sum, its fee; nothing to pay on for a payment never opened there.
     */
    private function page(Payment $payment, ?string $amount = null, ?CardTransfer $card = null, ?string $fee = null): PaymentInitiation
    {
        return $payment->link === null
            ? PaymentInitiation::instant()
            : PaymentInitiation::link(new PayLink($payment->link, $payment->link_expires_at?->toDateTimeImmutable(), $amount ?? $payment->amount, $card, $fee));
    }
}
