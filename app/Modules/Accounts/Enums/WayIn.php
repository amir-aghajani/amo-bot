<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * A kind of way into a customer's account: a Telegram account, a Google account, an email (with its password), a mobile
 * number (a code by SMS: the one the customer shared with the bot, or proved on the website). An account has at most one
 * of each, in its own column of `users`.
 */
enum WayIn: string
{
    case Telegram = 'telegram';
    case Google = 'google';
    case Email = 'email';
    case Phone = 'phone';

    /** The column of `users` that holds it. */
    public function column(): string
    {
        return match ($this) {
            self::Telegram => 'telegram_id',
            self::Google => 'google_sub',
            self::Email => 'email',
            self::Phone => 'phone',
        };
    }

    /** Its name as the customer reads it: in merge refusals and the notices of a way in added or taken away. */
    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'تلگرام',
            self::Google => 'گوگل',
            self::Email => 'ایمیل',
            self::Phone => 'شماره موبایل',
        };
    }

    /** @return list<string> Every kind's column. */
    public static function columns(): array
    {
        return array_map(static fn(self $kind): string => $kind->column(), self::cases());
    }
}
