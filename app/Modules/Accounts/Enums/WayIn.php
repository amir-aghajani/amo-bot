<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * A kind of way into a customer's account: a Telegram account, a Google account, an email (with its password). An account
 * has at most one of each, in its own column of `users`.
 */
enum WayIn: string
{
    case Telegram = 'telegram';
    case Google = 'google';
    case Email = 'email';

    /** The column of `users` that holds it. */
    public function column(): string
    {
        return match ($this) {
            self::Telegram => 'telegram_id',
            self::Google => 'google_sub',
            self::Email => 'email',
        };
    }

    /** Its name as the customer reads it: in merge refusals and the notices of a way in added or taken away. */
    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'تلگرام',
            self::Google => 'گوگل',
            self::Email => 'ایمیل',
        };
    }

    /** @return list<string> Every kind's column. */
    public static function columns(): array
    {
        return array_map(static fn(self $kind): string => $kind->column(), self::cases());
    }
}
