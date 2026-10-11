<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

use App\Core\Exceptions\DomainRuleException;

/** Telegram could not be asked what an operation needed (out of reach, a flood limit): a 502, nothing changed. */
final class TelegramUnreachableException extends DomainRuleException
{
    /** The message for Telegram not answering, wherever it happens (a token check, a channel lookup, a receipt, a sign-in). */
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
