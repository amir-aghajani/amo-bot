<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * What came of a message the bot wrote a customer on its own initiative (Services\CustomerChats::send()) — or, a notice
 * to a customer Telegram cannot reach — no Telegram account, or one that turned the bot away —, emailed to them
 * (Services\Notices) — what a screen says when telling them was the point (a payment's reminder, an agent's new terms).
 */
enum Delivery: string
{
    /** Telegram took it. */
    case Told = 'told';

    /** Telegram could not take it to them — no Telegram account, or they turned the bot away — and the shop's email took it to their address. */
    case Emailed = 'emailed';

    /** They turned the bot away — blocked it, or their account is gone —: known before, or learned now; and no email took it. */
    case TurnedAway = 'turned_away';

    /** Telegram — or, for an email, the mail server — was out of reach, or asked for patience: this message is lost, the next may go. */
    case Unreachable = 'unreachable';

    /** Telegram refused what was sent. */
    case Refused = 'refused';

    /** They have no Telegram account the bot knows (they signed up on the website), and no email went to them: nothing was sent. */
    case NoTelegram = 'no_telegram';
}
