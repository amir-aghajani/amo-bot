<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Manual;

use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\DTO\CardTransfer;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;

/** A card-to-card method's gateway: shows the card; settling it is support's (or the review window's) approval. */
final class ManualGateway implements GatewayInterface
{
    public function __construct(private readonly CardTransfer $transfer) {}

    /** The key a card method's row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'manual';
    }

    /** Minutes a receipt sent in the bot may wait for a person before AutoApproveReceiptsTask accepts it; 0 = only by hand. */
    public static function reviewWindow(PaymentMethod $method): int
    {
        return ReviewWindow::of($method->config);
    }

    public function initiate(): PaymentInitiation
    {
        return PaymentInitiation::transfer($this->transfer);
    }

    /**
     * A transfer between cards settles by being approved — by a person, or by the review window running out — and
     * leaves the shop no id of its own. A refused receipt never gets here (PaymentService::reject()): the answer is yes.
     */
    public function settle(Payment $payment): PaymentResult
    {
        return PaymentResult::success();
    }
}
