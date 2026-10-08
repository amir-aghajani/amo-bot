<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

/**
 * The Telegram account a website's sign-in proved — its token checked the one way (Services\TelegramSignIn::account()):
 * the numeric id the bot knows its customers by, and its profile as Telegram gave it (Users\Services\Customers::profile()).
 */
final class TelegramAccount
{
    /** @param array{username: string|null, first_name: string|null, last_name: string|null} $profile */
    public function __construct(
        public readonly int $id,
        public readonly array $profile,
    ) {}
}
