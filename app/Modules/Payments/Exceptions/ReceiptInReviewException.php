<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\Payment;

/**
 * While the shop takes one receipt at a time (PaymentSettings::oneReceiptAtATime()), a customer whose receipt waits for
 * review sends no other receipt and starts no other card payment (Services\ReceiptsInReview): a 422 on `status` on the
 * website, the bot's own words there. Nothing was ordered or kept.
 */
final class ReceiptInReviewException extends DomainRuleException
{
    public const MESSAGE = 'رسید سفارش #%d هنوز در حال بررسی پشتیبانی است؛ لطفا تا اعلام نتیجه صبر کنید و بعد رسید تازه بفرستید یا پرداخت کارت به کارت تازه‌ای شروع کنید.';

    /** @param Payment $inReview The customer's payment whose receipt support is reviewing */
    public function __construct(public readonly Payment $inReview)
    {
        parent::__construct(sprintf(self::MESSAGE, $inReview->order_id));
    }

    protected function field(): string
    {
        return 'status';
    }
}
