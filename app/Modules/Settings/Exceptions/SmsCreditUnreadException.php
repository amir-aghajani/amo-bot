<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The SMS account's credit, asked of its provider for the owner, not told: a 502 in the provider's words. */
final class SmsCreditUnreadException extends DomainRuleException
{
    /** @param string $reason Why, in the provider's words (SmsFailedException::$reason) */
    public function __construct(string $reason)
    {
        parent::__construct('اعتبار حساب پیامک خوانده نشد: ' . $reason);
    }

    public function status(): int
    {
        return 502;
    }
}
