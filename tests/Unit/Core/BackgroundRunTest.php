<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Scheduling\BackgroundRun;
use PHPUnit\Framework\TestCase;

/**
 * A scheduler run in a process of its own: started and left to itself, one at a time, what it printed and how it ended
 * read once it is over — after which the next may start.
 */
final class BackgroundRunTest extends TestCase
{
    public function testARunIsStartedLeftToItselfAndReadOnceItIsOver(): void
    {
        $run = new BackgroundRun([PHP_BINARY, '-r', 'echo "  ✔ App\\\\Tasks\\\\Ticked\n"; exit(3);']);

        self::assertTrue($run->start());
        self::assertFalse($run->start(), 'one run at a time: the next waits until this one is read');

        $over = self::waitFor($run);
        self::assertSame(3, $over['code']);
        self::assertStringContainsString('✔ App\\Tasks\\Ticked', $over['output']);
        self::assertNull($run->finished(), 'read once');

        self::assertTrue($run->start(), 'and then the next one starts');
        self::assertSame(3, self::waitFor($run)['code']);
    }

    public function testNothingIsOverBeforeAnythingStarted(): void
    {
        $run = new BackgroundRun([PHP_BINARY, '-r', '']);

        self::assertNull($run->finished());
        self::assertFalse($run->running());
        self::assertNull($run->failure());
    }

    /**
     * A PHP that is not there, or will not run: refused as proc_open starts it (Windows), or its process ending with the
     * shell's 127 (elsewhere). Either way the runs from here are over, and why is said — the poller runs the scheduler
     * itself from then on.
     */
    public function testARunWhosePhpCannotRunEndsTheRunsFromHere(): void
    {
        $run = new BackgroundRun([dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'no-such-php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), '-r', '']);

        if ($run->start()) {
            self::assertSame(127, self::waitFor($run)['code']);
        }

        self::assertNotNull($run->failure(), 'why none can be started');
        self::assertFalse($run->running());
        self::assertFalse($run->start(), 'and none is tried again');
    }

    /** Under a web server PHP_BINARY is php-cgi or php-fpm, which runs no console command: there, none is started. */
    public function testOnlyTheCommandLineStartsOne(): void
    {
        self::assertSame(function_exists('proc_open'), BackgroundRun::available(), 'the suite runs on the command line');

        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        if (!is_file($cgi)) {
            self::markTestSkipped("No php-cgi beside {$cgi}: a web server's PHP cannot be played here.");
        }
        $script = tempnam(sys_get_temp_dir(), 'amobot-sapi-');
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . '; echo PHP_SAPI, " ", App\Core\Scheduling\BackgroundRun::available() ? "starts one" : "starts none";');
        try {
            $process = proc_open([$cgi, '-q', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $said = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            unlink($script);
        }

        self::assertSame('cgi-fcgi starts none', $said);
    }

    /** @return array{code: int, output: string} The run, once its process is over (a PHP that starts and stops at once). */
    private static function waitFor(BackgroundRun $run): array
    {
        $until = microtime(true) + 30;
        while (microtime(true) < $until) {
            $over = $run->finished();
            if ($over !== null) {
                return $over;
            }
            usleep(5_000);
        }

        self::fail('The run did not end.');
    }
}
