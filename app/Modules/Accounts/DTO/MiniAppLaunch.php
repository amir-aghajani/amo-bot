<?php

declare(strict_types=1);

namespace App\Modules\Accounts\DTO;

use Illuminate\Support\Carbon;

/**
 * A mini app's launch data that held (Support\LaunchData::read()): the Telegram account it is about — the numeric id the
 * bot knows its customers by, and its profile — and when Telegram made it, which is when the account was last proven.
 */
final readonly class MiniAppLaunch
{
    /** @param array{username: string|null, first_name: string|null, last_name: string|null} $profile */
    public function __construct(
        public int $telegramId,
        public array $profile,
        public Carbon $madeAt,
    ) {}
}
