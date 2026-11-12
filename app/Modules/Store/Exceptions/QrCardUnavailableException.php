<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A service's QR card the server cannot draw now (Telegram\Qr\QrCard::render() gave none: no GD, a background it cannot
 * read, a link no code holds): a 503, the link itself still the customer's to copy.
 */
final class QrCardUnavailableException extends DomainRuleException
{
    public const MESSAGE = 'تصویر QR این سرویس الان ساخته نشد؛ لینک اشتراک را کپی کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function status(): int
    {
        return 503;
    }
}
