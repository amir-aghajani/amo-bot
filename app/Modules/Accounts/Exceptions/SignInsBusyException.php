<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The shop keeps as many sign-ins begun — nonces, redirect states — as it takes (Services\AuthChallenges::LIVE_MAX): no
 * new one until some expire, a 503 in the customer's words. Whoever asks for them by the thousand, from address after
 * address, fills no table without end.
 */
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
