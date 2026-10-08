<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Logging\Acting;

/**
 * Who the request being answered is read by: the principal its guard put on it — the panels' auth middleware, the website's
 * admins' (Store\Http\StaffMiddleware) —, for what an answer shows by its reader rather than by its shop (who of support
 * decided something, Services\Reviewers: the owner's login is no one's to read but the owner's). Set for the work a guard
 * hands on and put back after it (run(), as CurrentBot::run()), so nothing of one request is left for the next; the log
 * names them for the rest of the request (Core\Logging\Acting). Nothing set — the bot, a task, a customer's own request —
 * nobody of the shop's people reads the answer.
 */
final class CurrentPrincipal
{
    private static ?Principal $current = null;

    public static function get(): ?Principal
    {
        return self::$current;
    }

    /**
     * Do the work for `$principal`, and come back to whom it was for before (even when it throws).
     *
     * @template T
     * @param \Closure(): T $work
     * @param-immediately-invoked-callable $work
     * @return T
     */
    public static function run(Principal $principal, \Closure $work): mixed
    {
        $before = self::$current;
        self::$current = $principal;
        Acting::begin($principal->actor()->label());

        try {
            return $work();
        } finally {
            self::$current = $before;
        }
    }
}
