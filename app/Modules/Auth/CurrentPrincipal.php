<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Logging\Acting;

/**
 * The principal the current request is answered for (set by a guard through run()), for what an answer shows by its
 * reader — Services\Reviewers hides the owner's login from anyone but the owner. Null for the bot, a task or a
 * customer's own request.
 */
final class CurrentPrincipal
{
    private static ?Principal $current = null;

    public static function get(): ?Principal
    {
        return self::$current;
    }

    /**
     * Run `$work` for `$principal`, restoring the previous principal afterwards, even on a throw.
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
