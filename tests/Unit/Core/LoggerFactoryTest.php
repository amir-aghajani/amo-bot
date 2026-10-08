<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config\Repository as Config;
use App\Core\Logging\LogFile;
use App\Core\Logging\LoggerFactory;
use App\Core\Logging\RedactingLineFormatter;
use App\Core\Support\Files;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Tests\TestCase;

/**
 * The app's log: its level as LOG_LEVEL says (a typo is no reason to stop the app), every line written with the secrets
 * taken out — the message, the exception in its context, each previous one and the trace —, a record one entry whatever
 * it holds, and a log that cannot be written no reason to stop either.
 */
final class LoggerFactoryTest extends TestCase
{
    private const TOKEN = '7123456789:AAH4b2-Vk9_xT0qLmNcZr8sYwE3fJdP1uGo';

    public function testTheLevelIsReadWhateverItsCaseAndAMisspeltOneMeansInfo(): void
    {
        self::assertSame(Level::Warning, $this->handlerOf($this->logger('WARNING'))->getLevel());
        self::assertSame(Level::Debug, $this->handlerOf($this->logger('debug'))->getLevel());
        self::assertSame(Level::Info, $this->handlerOf($this->logger('verbose'))->getLevel(), 'a typo in LOG_LEVEL must not take the app down');
    }

    public function testEveryLineIsWrittenWithTheSecretsTakenOut(): void
    {
        $logger = $this->logger('info');
        foreach ($logger->getHandlers() as $handler) {
            self::assertInstanceOf(FormattableHandlerInterface::class, $handler);
            self::assertInstanceOf(RedactingLineFormatter::class, $handler->getFormatter(), 'whatever handler writes the line');
        }

        // The suite's own log (LOG_FILE): read back from where it stood before this line.
        $handler = $this->handlerOf($logger);
        $file = (string) $handler->getUrl();
        $from = is_file($file) ? (int) filesize($file) : 0;
        $failure = new \RuntimeException('Polling failed', 0, new \RuntimeException('cURL error 28 for https://api.telegram.org/bot' . self::TOKEN . '/getUpdates'));
        $logger->error('Telegram request failed: https://api.telegram.org/bot' . self::TOKEN . '/getMe', ['exception' => $failure]);
        $handler->close();

        $written = (string) file_get_contents($file, offset: $from);
        self::assertStringContainsString('bot***/getMe', $written, 'the line is there, its message masked');
        self::assertStringContainsString('bot***/getUpdates', $written, 'and the previous exception\'s');
        self::assertStringNotContainsString(self::TOKEN, $written);
    }

    public function testARecordIsOneEntryWhateverItHolds(): void
    {
        $logger = $this->logger('info');
        $handler = $this->handlerOf($logger);
        $file = (string) $handler->getUrl();
        $from = is_file($file) ? (int) filesize($file) : 0;

        // What a customer, a panel or a browser writes can carry line breaks and a terminal's control codes.
        $logger->warning("Order {id} failed: the panel said no\n[2026-01-01 00:00:00] app.INFO: a forged line", [
            'id' => 7,
            'name' => "amir\r\n[2026-01-01 00:00:01] app.INFO: another\x1b[2J\u{202E}gpj.exe",
        ]);
        $handler->close();

        $lines = explode("\n", rtrim((string) file_get_contents($file, offset: $from), "\n"));
        self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] app\.WARNING: Order 7 failed/', $lines[0]);
        self::assertGreaterThan(2, count($lines));
        foreach (array_slice($lines, 1) as $line) {
            self::assertStringStartsWith(RedactingLineFormatter::CONTINUATION, $line, 'every line after the first is the record\'s own');
        }
        self::assertStringNotContainsString("\x1b", implode("\n", $lines));
        self::assertStringNotContainsString("\u{202E}", implode("\n", $lines));
    }

    /** The log holds addresses, admins' work and customers' words: its folder and its files are made as the app's own files are. */
    public function testTheLogIsMadeAsTheAppsOwnFilesAre(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows keeps no owner, group and others apart: CI runs this.');
        }

        $hosts = [
            'PHP as the account: its own alone' => [true, Files::FOLDER_MODE, Files::FILE_MODE],
            'PHP as another user: the account reads it' => [false, Files::SHARED_FOLDER_MODE, Files::SHARED_FILE_MODE],
        ];
        foreach ($hosts as $host => [$ownerOnly, $folderMode, $fileMode]) {
            $folder = $this->scratchDir() . '/logs-' . ($ownerOnly ? 'owner' : 'shared');
            Files::ownerOnly($ownerOnly);
            try {
                $handler = LogFile::at($folder . '/app.log', 0, Level::Info);
                (new Logger('app', [$handler]))->info('Payment 7 settled');
                $handler->close();
            } finally {
                Files::ownerOnly(Files::processOwns($this->app()->basePath()));
            }

            self::assertSame([$folderMode, $fileMode], [fileperms($folder) & 0o777, fileperms((string) $handler->getUrl()) & 0o777], $host);
        }
    }

    public function testALogThatCannotBeWrittenGoesToPhpsOwnAndTheWorkGoesOn(): void
    {
        $folder = $this->scratchDir() . '/logs';
        file_put_contents($folder, 'a file where the log\'s folder would be');
        $fallback = $this->scratchDir() . '/php-errors.log';
        $was = ini_set('error_log', $fallback);
        try {
            $handler = LogFile::at($folder . '/app.log', 0, Level::Info);
            $handler->setFormatter(new RedactingLineFormatter());
            (new Logger('app', [$handler]))->error('Telegram request failed: https://api.telegram.org/bot' . self::TOKEN . '/getMe');
        } finally {
            ini_set('error_log', (string) $was);
        }

        $written = (string) file_get_contents($fallback);
        self::assertStringContainsString('app.ERROR: Telegram request failed: https://api.telegram.org/bot***/getMe', $written);
        self::assertStringNotContainsString(self::TOKEN, $written);
    }

    /** The app's logger, as LoggerFactory makes it, writing to the suite's log (never rotating anything away). */
    private function logger(string $level): Logger
    {
        $logger = (new LoggerFactory())($this->app(), new Config(['logging' => ['level' => $level, 'file' => 'tests.log', 'max_files' => 0]]));
        self::assertInstanceOf(Logger::class, $logger);

        return $logger;
    }

    private function handlerOf(Logger $logger): StreamHandler
    {
        $handler = $logger->getHandlers()[0] ?? null;
        self::assertInstanceOf(LogFile::class, $handler, 'the log is a file — PHP\'s own log when that cannot be written');

        return $handler;
    }
}
