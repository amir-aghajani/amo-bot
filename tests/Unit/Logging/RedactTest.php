<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Core\Logging\Redact;
use App\Core\Logging\RedactingLineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * No secret reaches a log line or an error message: a bot token in Telegram's URLs (a transport error carries the
 * whole URL), a bare token, the webhook secret and the cron token in a request's path.
 */
final class RedactTest extends TestCase
{
    private const TOKEN = '7123456789:AAH4b2-Vk9_xT0qLmNcZr8sYwE3fJdP1uGo';

    public function testTokensInTelegramsUrlsAndBareAreTakenOut(): void
    {
        self::assertSame(
            'Telegram request sendMessage failed: cURL error 6 for https://api.telegram.org/bot***/sendMessage',
            Redact::text('Telegram request sendMessage failed: cURL error 6 for https://api.telegram.org/bot4242:bot-token_A-b/sendMessage'),
        );
        self::assertSame('GET https://api.telegram.org/file/bot***/photos/1.jpg', Redact::text('GET https://api.telegram.org/file/bot' . self::TOKEN . '/photos/1.jpg'));
        self::assertSame('token ***', Redact::text('token ' . self::TOKEN));
    }

    public function testPathSecretsAreTakenOut(): void
    {
        self::assertSame('POST /webhooks/telegram/*** -> 500', Redact::text('POST /webhooks/telegram/9f8e7d6c5b4a -> 500'));
        self::assertSame('POST /webhooks/telegram/bot/7/*** -> 500', Redact::text('POST /webhooks/telegram/bot/7/a1b2c3d4 -> 500'));
        self::assertSame('GET /shop/cron/*** -> 500', Redact::text('GET /shop/cron/0123456789abcdef -> 500'));
    }

    public function testOrdinaryTextStays(): void
    {
        $text = 'Sync of server 3 at 2026-10-05 20:44:25 took 12:30 minutes; order #42 paid.';

        self::assertSame($text, Redact::text($text));
    }

    public function testTheLogLineIsRedactedWholeExceptionsIncluded(): void
    {
        $previous = new \RuntimeException('cURL error 28 for https://api.telegram.org/bot' . self::TOKEN . '/getUpdates');
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'Polling failed', ['exception' => new \RuntimeException('wrapped', 0, $previous)]);

        $line = (new RedactingLineFormatter(null, null, true, true, true))->format($record);

        self::assertStringNotContainsString(self::TOKEN, $line);
        self::assertStringContainsString('bot***/getUpdates', $line);
    }
}
