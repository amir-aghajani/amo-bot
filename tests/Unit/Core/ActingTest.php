<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Http\RequestId;
use App\Core\Logging\Acting;
use App\Core\Logging\ActorProcessor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Tests\TestCase;

/**
 * Who a request acts for names every line it writes from the moment its guard says so (`actor` among the line's extras)
 * to the end of that request — a failure the error handler logs after the guard let go too —, and only that request's:
 * the next one acts for nobody until its own guard says so, and work outside a request (the bot, a task) for nobody.
 */
final class ActingTest extends TestCase
{
    public function testTheActorNamesTheLinesOfItsRequestAlone(): void
    {
        $lines = new TestHandler();
        $log = new Logger('test', [$lines], [new ActorProcessor()]);

        Acting::begin('owner root');
        $log->info('a task');
        RequestId::begin('0123456789abcdef');
        $log->info('before its guard');
        Acting::begin('staff @sara #7');
        $log->info('a decision');
        $log->error('a failure the error handler logs');
        RequestId::begin('fedcba9876543210');
        $log->info('the next request');

        self::assertSame(
            ['a task' => null, 'before its guard' => null, 'a decision' => 'staff @sara #7', 'a failure the error handler logs' => 'staff @sara #7', 'the next request' => null],
            array_column(array_map(static fn(LogRecord $line): array => [$line->message, $line->extra['actor'] ?? null], $lines->getRecords()), 1, 0),
        );
    }
}
