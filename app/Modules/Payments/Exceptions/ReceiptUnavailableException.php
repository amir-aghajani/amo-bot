<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A payment's receipt is not there to show (404): none was sent, Telegram no longer hands the file over, or the picture
 * uploaded from the website was deleted (its order expired). Telegram out of reach is another answer
 * (Telegram\Api\TelegramUnreachableException, a 502): the receipt is still there.
 */
final class ReceiptUnavailableException extends DomainRuleException
{
    public static function none(): self
    {
        return new self('این پرداخت رسیدی ندارد.');
    }

    public static function gone(): self
    {
        return new self('فایل این رسید دیگر در تلگرام نیست.');
    }

    public static function removed(): self
    {
        return new self('فایل این رسید دیگر روی سرور نیست.');
    }

    public function status(): int
    {
        return 404;
    }
}
