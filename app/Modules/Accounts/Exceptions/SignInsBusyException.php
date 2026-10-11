<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A 503: the shop holds as many begun sign-ins (nonces, redirect states) as it takes (AuthChallenges::LIVE_MAX), until some expire. */
final class SignInsBusyException extends DomainRuleException
{
    public const MESSAGE = 'ورود به وب‌سایت این فروشگاه الان شلوغ است؛ چند دقیقه دیگر دوباره امتحان کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function status(): int
    {
        return 503;
    }
}
