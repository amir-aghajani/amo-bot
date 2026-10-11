<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A payment's receipt is not there to show (404): none sent, Telegram no longer has the file, or the upload was deleted.
 * Telegram being out of reach is a 502 instead (TelegramUnreachableException).
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
