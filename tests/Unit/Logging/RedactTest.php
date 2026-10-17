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
 * whole URL), a bare token, the webhook secret, the cron token and a payment callback's secret in a request's path.
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
        // An online payment's callback: its id stays, its secret goes — before a query, or in a whole address.
        self::assertSame('GET /payments/callback/12/***?Authority=A1&Status=OK', Redact::text('GET /payments/callback/12/0123456789abcdef0123456789abcdef01234567?Authority=A1&Status=OK'));
        self::assertSame('POST https://shop.example/payments/callback/12/*** -> 503', Redact::text('POST https://shop.example/payments/callback/12/0123456789abcdef0123456789abcdef01234567 -> 503'));
    }

    public function testAKeyAnAddressCarriesInItsQueryIsTakenOut(): void
    {
        self::assertSame(
            'Plisio is out of reach: cURL error 28 for https://api.plisio.net/api/v1/operations/5ee0?api_key=*** (timeout)',
            Redact::text('Plisio is out of reach: cURL error 28 for https://api.plisio.net/api/v1/operations/5ee0?api_key=Sk_9f8e-7d6c5b4a (timeout)'),
        );
        self::assertSame('GET /x?a=1&token=***&b=2', Redact::text('GET /x?a=1&token=abc123&b=2'));
        self::assertSame('GET /x?keys=1&monkey=2', Redact::text('GET /x?keys=1&monkey=2'), 'only a key of that name');
    }

    public function testAnAddresssLoginIsTakenOut(): void
    {
        self::assertSame('proxy socks5h://***@proxy.example.com:1080 refused', Redact::text('proxy socks5h://shop:s3cret%40pw@proxy.example.com:1080 refused'));
        self::assertSame('https://***@panel.example.com/base', Redact::text('https://admin:pw@panel.example.com/base'));
        self::assertSame('GET https://panel.example.com/a@b and mail@example.com', Redact::text('GET https://panel.example.com/a@b and mail@example.com'), 'an @ after the host, or in an address of mail, is no login');
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
