<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Forms\Fields\Field;
use App\Core\Mail\Drivers\Native;
use App\Core\Mail\Drivers\Resend;
use App\Core\Mail\Drivers\Smtp;
use App\Core\Mail\MailDriver;
use App\Core\Mail\MailTransport;
use App\Core\Mail\ResendTransport;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\Support\FakePanel;
use Tests\TestCase;

/**
 * How config.php's MAIL_* settings become symfony/mailer's transport: MAIL_TRANSPORT names a mail driver, which makes
 * its transport of its own settings as its form reads them — an SMTP account by its encryption (STARTTLS required, TLS
 * from the first byte, or neither) and its password as it is, the host's own mail, Resend's API — or none. Nothing here
 * reaches a mail server: a transport connects only as it sends.
 */
final class MailTransportTest extends TestCase
{
    /** An SMTP account as config.php writes it. */
    private const SMTP = ['MAIL_HOST' => 'mail.example.com', 'MAIL_PORT' => '587', 'MAIL_ENCRYPTION' => 'tls', 'MAIL_USERNAME' => 'no-reply@example.com', 'MAIL_PASSWORD' => 'p@ss/w:rd 1'];

    /** @return array<string, array{array<string, string>, int, bool, bool, bool}> settings, port, TLS from the first byte, STARTTLS required, STARTTLS tried */
    public static function encryptions(): array
    {
        return [
            'STARTTLS, required' => [self::SMTP, 587, false, true, true],
            'TLS from the first byte' => [['MAIL_PORT' => '465', 'MAIL_ENCRYPTION' => 'ssl'] + self::SMTP, 465, true, false, true],
            'no encryption' => [['MAIL_PORT' => '25', 'MAIL_ENCRYPTION' => 'none'] + self::SMTP, 25, false, false, false],
        ];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('encryptions')]
    public function testAnSmtpAccountIsReachedAsItsEncryptionSays(array $settings, int $port, bool $tls, bool $requireTls, bool $autoTls): void
    {
        $transport = self::transport('smtp', $settings);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(['mail.example.com', $port, $tls], [$stream->getHost(), $stream->getPort(), $stream->isTLS()]);
        self::assertSame([$requireTls, $autoTls], [$transport->isTlsRequired(), $transport->isAutoTls()], 'STARTTLS or nothing with tls: the password never goes in the clear');
    }

    public function testAnSmtpAccountIsSignedInToByItsOwnPasswordAndGivenUpOnInTime(): void
    {
        $transport = self::transport('smtp', self::SMTP);

        self::assertInstanceOf(EsmtpTransport::class, $transport);
        self::assertSame(['no-reply@example.com', 'p@ss/w:rd 1'], [$transport->getUsername(), $transport->getPassword()], 'the password as typed, whatever it holds');
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(15.0, $stream->getTimeout(), 'a customer waits for the mail they asked for: not a minute');

        $anonymous = self::transport('smtp', ['MAIL_USERNAME' => '', 'MAIL_PASSWORD' => ''] + self::SMTP);
        self::assertInstanceOf(EsmtpTransport::class, $anonymous);
        self::assertSame('', $anonymous->getUsername(), 'no account: nothing signed in to');
    }

    public function testResendIsItsApiWithTheKey(): void
    {
        self::assertInstanceOf(ResendTransport::class, self::transport('resend', ['MAIL_RESEND_API_KEY' => ' re_test_1234567890abcdef ']));
    }

    public function testSettingsThatMakeNoTransportSendNothingAndSayWhy(): void
    {
        $said = new TestHandler();
        $logger = new Logger('mail', [$said]);
        $why = static fn(string $words): bool => $said->hasWarningThatPasses(static fn(LogRecord $record): bool => str_contains((string) ($record->context['reason'] ?? ''), $words));

        self::assertNull(self::transport('resend', ['MAIL_RESEND_API_KEY' => ''], $logger), 'Resend without its key');
        self::assertTrue($why('MAIL_RESEND_API_KEY'));
        self::assertNull(self::transport('smtp', ['MAIL_HOST' => ' '] + self::SMTP, $logger), 'SMTP without a server');
        self::assertTrue($why('MAIL_HOST'));
        self::assertNull(self::transport('sendgrid', self::SMTP, $logger), 'a driver the shop does not have');
        self::assertTrue($why('sendgrid'));
    }

    public function testNoneIsNoTransport(): void
    {
        self::assertNull(self::transport('none', self::SMTP));
        self::assertNull(MailTransport::fromConfig(new Config([]), self::drivers(), new NullLogger()), 'a config.php of before the mail had none');
    }

    public function testEveryDriverSaysWhoItCanSendFrom(): void
    {
        foreach (self::drivers()->all() as $driver) {
            self::assertIsString($driver->describe()->traits[MailDriver::SENDER] ?? null, $driver->key());
        }
        self::assertSame([587, 'tls'], array_slice(array_values((new Smtp())->describe()->form->values(static fn(Field $field): mixed => null)), 1, 2), 'what an SMTP account runs with while config.php does not say');
    }

    /**
     * The transport config.php's MAIL_* settings name: MAIL_TRANSPORT and the rest, as config/mail.php reads them.
     *
     * @param array<string, string> $settings
     */
    private static function transport(string $kind, array $settings, ?Logger $logger = null): ?TransportInterface
    {
        return MailTransport::fromConfig(new Config(['mail' => ['transport' => $kind, 'settings' => $settings]]), self::drivers(), $logger ?? new NullLogger());
    }

    /** @return Registry<MailDriver> */
    private static function drivers(): Registry
    {
        return new Registry([new Smtp(), new Native(), new Resend((new FakePanel())->client())]);
    }
}
