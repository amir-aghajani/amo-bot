<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Http\RequestId;

/**
 * Who the request being answered acts for — the owner signed in to their panel, an agent, one of a shop's admins on its
 * website —, as one line of the log names them (ActorProcessor): the guard that admits a principal says so (begin()),
 * and it holds for the rest of that request, as its id does (RequestId) — a failure the error handler logs after the
 * guard let go names them too. It belongs to the request it was said in: the next one (its own id) acts for nobody
 * until its guard says otherwise. Core names nobody: what it holds is the guard's own words for them.
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

    /** The request being answered acts for `$actor` from now on, to its end; outside a request, nothing is said. */
    public static function begin(string $actor): void
    {
        $request = RequestId::current();
        self::$current = $request === null ? null : [$request, $actor];
    }
}
