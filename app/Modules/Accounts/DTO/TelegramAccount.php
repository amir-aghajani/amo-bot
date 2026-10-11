<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/** A Telegram account a sign-in proved (TelegramSignIn::account()): the numeric id the bot knows customers by, and its profile. */
final class TelegramAccount
{
    /** @param array{username: string|null, first_name: string|null, last_name: string|null} $profile */
    public function __construct(
        public readonly int $id,
        public readonly array $profile,
    ) {}
}
