<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Enums;

/**
 * A kind of way into a customer's account on the shop's website — the one list of them: a Telegram account, a Google
 * account, an email (with its password). An account has one of each kind at most, kept in its own column of `users`
 * (column()); the customer's words name it (label()): a merge's refusal, a notice that one was added or taken away.
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

    /** Its name in the customer's words. */
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
