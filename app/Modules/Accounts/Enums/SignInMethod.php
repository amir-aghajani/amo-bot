<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * How a website or mini app session was signed in (`customer_sessions.method`): Telegram, the mini app's launch data
 * (which Telegram signs with the bot's token), Google, a code sent to the account's mobile by SMS (with the second step,
 * on an account that asks one), a password alone (a sign-up, a reset, a sign-in) or a password and its second step. A
 * stronger way proven on the session later replaces it. The website's admin API may ask it to be strong(). A panel's
 * preview of the mini app as one of the customers (Preview) is no sign-in at all: nothing of theirs was proven
 * (Services\CustomerSessions::openPreview()).
 */
enum SignInMethod: string
{
    case Telegram = 'telegram';
    case MiniApp = 'mini_app';
    case Google = 'google';
    case Phone = 'phone';
    case PhoneAndCode = 'phone_2fa';
    case Password = 'password';
    case PasswordAndCode = 'password_2fa';
    case Preview = 'preview';

    /**
     * A way neither a leaked password nor the mobile number alone opens: a provider's sign-in, or a password or a code by
     * SMS with its second step. A code by SMS alone is not — a SIM swapped, or a number the carrier gave someone else,
     * reads it —, and a preview proves no way in, so never.
     */
    public function strong(): bool
    {
        return !in_array($this, [self::Password, self::Phone, self::Preview], true);
    }
}
