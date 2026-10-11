<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * How a website session was signed in (`customer_sessions.method`): Telegram, Google, a password alone (a sign-up, a
 * reset, a sign-in) or a password and its second step. A stronger way proven on the session later replaces it. The
 * website's admin API may ask it to be strong().
 */
enum SignInMethod: string
{
    case Telegram = 'telegram';
    case Google = 'google';
    case Password = 'password';
    case PasswordAndCode = 'password_2fa';

    /** A way a leaked password alone does not open: a provider's sign-in, or a password with its second step. */
    public function strong(): bool
    {
        return $this !== self::Password;
    }
}
