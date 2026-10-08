<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * A request whose work must not stop halfway — Telegram's webhook, the /cron address: what was started is finished
 * when the caller hangs up (Telegram's patience, a pinger's timeout), within a time limit of the request's own instead
 * of the host's default. The command line has no limit and keeps it.
 */
final class LongRunning
{
    public static function keepGoing(int $seconds): void
    {
        ignore_user_abort(true);

        // A host may have taken set_time_limit() away (disable_functions): the default limit stands then.
        if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
            set_time_limit($seconds);
        }
    }
}
