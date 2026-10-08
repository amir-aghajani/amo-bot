<?php

declare(strict_types=1);

namespace App\Modules\Support\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A ticket message's picture is not there to show (404): the message has none, Telegram no longer hands it over, or the
 * file uploaded is gone from the shop's. Telegram out of reach is another answer (Telegram\Api\
 * TelegramUnreachableException, a 502): the picture is still there.
 */
final class AttachmentUnavailableException extends DomainRuleException
{
    public static function none(): self
    {
        return new self('این پیام تصویری ندارد.');
    }

    public static function gone(): self
    {
        return new self('این تصویر دیگر در تلگرام نیست.');
    }

    public static function removed(): self
    {
        return new self('فایل این تصویر دیگر روی سرور نیست.');
    }

    public function status(): int
    {
        return 404;
    }
}
