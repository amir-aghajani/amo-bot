<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * For a request whose work must not stop halfway (the webhook, /cron, an update step): keep going when the caller hangs
 * up, within a time limit of its own instead of the host's default. Does nothing to the command line's (unlimited) time.
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
