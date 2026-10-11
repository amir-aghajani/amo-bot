<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Enums;

/** Why a gift code is not redeemed; message() is the website's words (the bot has an editable text for each). */
enum GiftCodeRefusal: string
{
    /** The shop takes no gift codes now (GiftCodeSettings). */
    case Off = 'off';
    case Empty = 'empty';
    /** No such code in the shop — a wrong one, counted against the customer and their network. */
    case Unknown = 'unknown';
    case Taken = 'taken';
    /** Taken — by this very customer. */
    case Yours = 'yours';
    case Expired = 'expired';
    /** Its reward cannot be given now (RewardUnavailableException): the code stays the customer's to try again. */
    case Unavailable = 'unavailable';

    public function message(): string
    {
        return match ($this) {
            self::Off => 'کد هدیه فعلا فعال نیست.',
            self::Empty => 'کد هدیه را بنویسید.',
            self::Unknown => 'این کد پیدا نشد؛ آن را دوباره بررسی کنید.',
            self::Taken => 'این کد پیش‌تر استفاده شده است.',
            self::Yours => 'این کد را پیش‌تر خودتان استفاده کرده‌اید.',
            self::Expired => 'مهلت استفاده از این کد تمام شده است.',
            self::Unavailable => 'این کد الان قابل استفاده نیست؛ کمی بعد دوباره امتحان کنید.',
        };
    }
}
