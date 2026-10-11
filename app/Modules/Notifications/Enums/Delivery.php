<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What came of a message the shop sent a customer on its own initiative (Services\CustomerChats::send(), or an email by
 * Services\Notices) — what a screen reports when telling them was the point (a payment's reminder, an agent's new terms).
 */
enum Delivery: string
{
    /** Telegram took it. */
    case Told = 'told';

    /** Telegram could not reach them (no account, or they turned the bot away) and the shop's email took it. */
    case Emailed = 'emailed';

    /** They turned the bot away (blocked it, or their account is gone), known before or learned now, and no email took it. */
    case TurnedAway = 'turned_away';

    /** Telegram (or, for an email, the mail server) was out of reach or asked to wait: this message is lost, the next may go. */
    case Unreachable = 'unreachable';

    /** Telegram refused what was sent. */
    case Refused = 'refused';

    /** They have no Telegram account (signed up on the website) and no email went to them: nothing was sent. */
    case NoTelegram = 'no_telegram';
}
