<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The owner's test email while no email can go: no transport, or no sender address, saved yet. */
final class MailNotReadyException extends DomainRuleException
{
    public const MESSAGE = 'ارسال ایمیل هنوز راه نیفتاده است؛ اول تنظیمات را ذخیره کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
