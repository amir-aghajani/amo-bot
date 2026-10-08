<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

use App\Core\Exceptions\DomainRuleException;

/**
 * Telegram could not be asked what an operation needed to know (out of reach, a flood limit) — the Bot API for a
 * panel's, its sign-in service for a website's: a 502, with the one word the shop uses for it, and nothing changed
 * meanwhile.
 */
final class TelegramUnreachableException extends DomainRuleException
{
    /** How the shop says Telegram did not answer, wherever it says so (a token checked, a channel looked up, a receipt, a sign-in). */
    public const MESSAGE = 'تلگرام در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.';

    public function __construct(\Throwable $previous)
    {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    public function status(): int
    {
        return 502;
    }
}
