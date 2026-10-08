<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use App\Support\Persian;

/**
 * Too many tries of one thing — signing in, reporting the panel's failures, opening tickets —: the caller waits
 * `$retryAfter` seconds before the next. A 429 whose `Retry-After` says the wait (Http\ErrorHandler), its message the
 * wait in words.
 */
final class TooManyAttemptsException extends DomainRuleException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }

    /** «{$what}؛ ۵ دقیقه دیگر دوباره امتحان کنید.» — the refusal of what must wait `$seconds`, in whole minutes. */
    public static function wait(string $what, int $seconds): self
    {
        return new self(sprintf('%s؛ %s دقیقه دیگر دوباره امتحان کنید.', $what, self::minutes($seconds)), $seconds);
    }

    /** A wait in whole minutes, in Persian digits — one at least. */
    public static function minutes(int $seconds): string
    {
        return Persian::digits((string) max(1, (int) ceil($seconds / 60)));
    }

    public function status(): int
    {
        return 429;
    }
}
