<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The owner's test SMS, or the credit asked, while no SMS can go: no driver, or not its settings, saved yet. */
final class SmsNotReadyException extends DomainRuleException
{
    public const MESSAGE = 'ارسال پیامک هنوز راه نیفتاده است؛ اول تنظیمات را ذخیره کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
