<?php

declare(strict_types=1);

namespace App\Modules\Payments\Drivers\Outside;

use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Models\Payment;

/** The gateway of money support took outside the shop: nothing to take here, so it settles at once. */
final class OutsideGateway implements GatewayInterface
{
    /** The key every shop's outside row is stored under (payment_methods.driver): its driver's. */
    public static function key(): string
    {
        return 'outside';
    }

    /** The driver's name, and the built-in row's label. */
    public static function label(): string
    {
        return 'خارج از فروشگاه';
    }

    public function initiate(Payment $payment): PaymentInitiation
    {
        return PaymentInitiation::instant();
    }

    /** Support saw the money already: the payment is settled as it is. */
    public function settle(Payment $payment): PaymentResult
    {
        return PaymentResult::success();
    }
}
