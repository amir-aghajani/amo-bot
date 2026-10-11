<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Http\RequestId;

/**
 * Who the current request acts for (the owner, an agent, a shop's admin), for the log's `actor` (ActorProcessor). Set
 * by the guard that admits the principal, it lasts to the end of that request — a failure logged after the guard is
 * done names them too — and is tied to the request's id, so the next request starts with nobody.
 */
final class Acting
{
    /** @var array{string, string}|null The request's id, and who it acts for */
    private static ?array $current = null;

    /** Who the request being answered acts for; null for nobody's: a customer's request, the bot, a task. */
    public static function current(): ?string
    {
        $request = RequestId::current();

        return $request !== null && self::$current !== null && self::$current[0] === $request ? self::$current[1] : null;
    }

    /** The current request acts for `$actor` until it ends; outside a request this does nothing. */
    public static function begin(string $actor): void
    {
        $request = RequestId::current();
        self::$current = $request === null ? null : [$request, $actor];
    }
}
