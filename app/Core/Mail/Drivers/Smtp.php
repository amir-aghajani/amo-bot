<?php

declare(strict_types=1);

namespace App\Core\Mail\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\Fields\Text;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Mail\MailDriver;
use App\Core\Mail\MailSettingsException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * An account on a mail server, over SMTP — one of the host's Email Accounts in cPanel, or any other: the server, its
 * port, how the connection is encrypted — STARTTLS required (`tls`), TLS from the first byte (`ssl`) or neither
 * (`none`) —, and the account signed in with. The password is kept with the account it was given for: moved to another
 * server or account, or to a connection less encrypted, it is typed again.
 */
final class Smtp implements MailDriver
{
    /** The password left blank while the account it was kept for moved — it never goes to another server, nor in clear text where it went encrypted. */
    public const PASSWORD_MOVED = 'سرور، رمزنگاری اتصال یا نام کاربری SMTP عوض شده است؛ رمز SMTP را دوباره وارد کنید.';

    /** How a connection is encrypted, in the order the screen offers it, and its words there. */
    private const ENCRYPTIONS = ['tls' => 'STARTTLS (پیشنهادی)', 'ssl' => 'SSL', 'none' => 'بدون رمزنگاری'];

    /** How long a mail server may take to answer, connecting included: a customer's request waits for the mail it sends. */
    private const TIMEOUT_SECONDS = 15;

    public function key(): string
    {
        return 'smtp';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'SMTP',
            description: 'یک حساب ایمیل روی یک سرور ایمیل؛ مثلا یکی از Email Accountهای cPanel هاست.',
            form: new Form($this->key(), [
                new Text(
                    'host',
                    'MAIL_HOST',
                    '',
                    label: 'سرور SMTP',
                    max: 255,
                    required: true,
                    pattern: '/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/',
                    mismatch: 'سرور SMTP را مثل mail.example.com بنویسید؛ بدون http:// و بدون پورت.',
                    spec: new FieldSpec('سرور SMTP', hint: 'بدون http:// و بدون پورت.', placeholder: 'mail.example.com', ltr: true),
                ),
                new Number('port', 'MAIL_PORT', 587, label: 'پورت SMTP', min: 1, max: 65535, spec: new FieldSpec('پورت', hint: '587 با STARTTLS، 465 با SSL، 25 بدون رمزنگاری.')),
                new Choice('encryption', 'MAIL_ENCRYPTION', 'tls', array_keys(self::ENCRYPTIONS), refusal: 'رمزگذاری اتصال باید tls، ssl یا none باشد.', spec: new FieldSpec('رمزنگاری اتصال', options: self::ENCRYPTIONS)),
                new Text('username', 'MAIL_USERNAME', '', label: 'نام کاربری SMTP', max: 255, spec: new FieldSpec('نام کاربری', hint: 'معمولا همان آدرس کامل حساب ایمیل.', ltr: true)),
                // A password is no token: its hint gives none of it away.
                new Secret(
                    'password',
                    'MAIL_PASSWORD',
                    '',
                    pattern: '/^[^\x00-\x1f\x7f]{1,255}$/u',
                    mismatch: 'رمز SMTP حداکثر 255 کاراکتر است، بدون کاراکترهای کنترلی.',
                    hint: static fn(string $password): string => '••••••••',
                    boundTo: ['host' => null, 'port' => null, 'encryption' => null, 'username' => null],
                    moved: self::PASSWORD_MOVED,
                    spec: new FieldSpec('رمز عبور', hint: 'رمز همان حساب ایمیل.'),
                ),
            ]),
            traits: [self::SENDER => 'یکی از حساب‌های همان سرور ایمیل؛ سرور از حساب‌های دیگر نمی‌فرستد.'],
        );
    }

    public function transport(array $values): TransportInterface
    {
        $host = is_string($values['host'] ?? null) ? $values['host'] : '';
        if ($host === '') {
            throw new MailSettingsException('MAIL_TRANSPORT smtp names no server (MAIL_HOST)');
        }
        $encryption = $values['encryption'] ?? 'tls';

        // TLS from the first byte for ssl; otherwise as the port says (465 is TLS's own), STARTTLS then required or not tried.
        $transport = new EsmtpTransport($host, is_int($values['port'] ?? null) ? $values['port'] : 0, $encryption === 'ssl' ? true : null);
        $transport->setRequireTls($encryption === 'tls');
        $transport->setAutoTls($encryption !== 'none');
        $transport->setUsername(is_string($values['username'] ?? null) ? $values['username'] : '');
        $transport->setPassword(is_string($values['password'] ?? null) ? $values['password'] : '');
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
        }

        return $transport;
    }
}
