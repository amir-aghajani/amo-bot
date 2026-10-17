<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\Payment;

/**
 * An order whose online payment is held for support (Services\OnlinePayments::isHeld()) — the gateway took money on it
 * that does not fit — is paid no other way meanwhile: the customer would pay twice. A 422 on `status` on the website, the
 * same words in the bot and on the gateway's return page. Nothing was ordered or kept.
 */
final class PaymentHeldException extends DomainRuleException
{
    /** %d: the order, a left-to-right mark (U+200E) before it — after the Persian words it would read «12#». */
    public const MESSAGE = "پرداخت سفارش \u{200E}#%d برای بررسی به پشتیبانی رسیده است؛ دوباره پرداخت نکنید و برای پیگیری به پشتیبانی پیام بدهید.";

    /** @param Payment $held The order's payment held for support */
    public function __construct(Payment $held)
    {
        parent::__construct(sprintf(self::MESSAGE, $held->order_id));
    }

    protected function field(): string
    {
        return 'status';
    }
}
