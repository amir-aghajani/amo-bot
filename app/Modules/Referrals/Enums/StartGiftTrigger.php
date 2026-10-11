<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Enums;

/**
 * When the start gift («هدیه شروع») is paid: on joining, or on the first purchase (the default — a fake newcomer made
 * for the gift buys nothing).
 */
enum StartGiftTrigger: string
{
    case Join = 'join';
    case Purchase = 'purchase';

    /** The moment worded for the customer, as the bot's terms say it: «با عضویت», «با اولین خرید». */
    public function words(): string
    {
        return match ($this) {
            self::Join => 'با عضویت',
            self::Purchase => 'با اولین خرید',
        };
    }
}
