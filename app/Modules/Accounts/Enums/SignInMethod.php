<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * How a customer's session on the website was signed in (`customer_sessions.method`): with their Telegram account, their
 * Google account, a password alone — a sign-up's, a reset's, a sign-in's —, or a password and its second step. A
 * stronger way proven again on the session since takes its place (Services\CustomerSessions::reauthenticated()). What
 * the shop's admins on the website are asked while it asks a strong sign-in (strong()).
 */
enum SignInMethod: string
{
    case Telegram = 'telegram';
    case Google = 'google';
    case Password = 'password';
    case PasswordAndCode = 'password_2fa';

    /**
     * A way in a password that leaked does not open by itself: a provider's sign-in (Telegram's, Google's), or a password
     * with its second step.
     */
    public function strong(): bool
    {
        return $this !== self::Password;
    }
}
