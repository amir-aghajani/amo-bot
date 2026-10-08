<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\LongRunning;
use PHPUnit\Framework\TestCase;

/**
 * A request whose work must not stop halfway (the webhook, /cron) goes on when its caller hangs up, within a limit of its
 * own — and the command line, which has none, keeps it so: a poller or a scheduled run is never cut short.
 */
final class LongRunningTest extends TestCase
{
    public function testTheWorkGoesOnWhenTheCallerHangsUpAndTheCommandLineKeepsNoLimit(): void
    {
        $abort = ignore_user_abort();
        $limit = (string) ini_get('max_execution_time');

        try {
            ignore_user_abort(false);
            LongRunning::keepGoing(5);

            self::assertSame(1, ignore_user_abort(), 'what was started is finished when the caller hangs up');
            self::assertSame('0', $limit, 'the command line runs without a limit');
            self::assertSame($limit, ini_get('max_execution_time'), 'and keeps running without one');
        } finally {
            ignore_user_abort((bool) $abort);
            set_time_limit((int) $limit);
        }
    }
}
