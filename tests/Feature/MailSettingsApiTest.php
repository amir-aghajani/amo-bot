<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Core\Mail\Drivers\Resend;
use App\Core\Mail\Drivers\Smtp;
use App\Core\Mail\MailFailedException;
use App\Modules\Settings\Exceptions\MailNotReadyException;
use Tests\HttpTestCase;

/**
 * The shop's email, the owner's «ایمیل» group of their settings (config.php's MAIL_*): the way out — none, or a mail
 * driver, each described with its form and shown with its settings —, the chosen driver's settings saved by its form
 * with who the emails come from — the SMTP password never sent back, and one kept never sent to another server or
 * account —, what the other drivers keep left as it is, every refusal at once; and a test email by the settings as
 * saved, which says why none went: nothing set up, or what the mail server said (its password never in it, nor in the
 * log).
 */
final class MailSettingsApiTest extends HttpTestCase
{
    /** What the owner's screen sends to send by an SMTP account. */
    private const SMTP = [
        'transport' => 'smtp',
        'host' => 'mail.example.com',
        'port' => '۴۶۵',
        'encryption' => 'ssl',
        'username' => 'no-reply@example.com',
        'password' => 'smtp p@ss/1',
        'from_address' => ' No-Reply@Example.com ',
        'from_name' => 'فروشگاه امو',
    ];

    private ConfigFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = $this->configFile(['APP_NAME' => 'AmoBot', 'APP_KEY' => 'base64:x']);
        $this->loginAsAdmin();
    }

    public function testTheGroupShowsEveryDriverWithWhatTheShopRunsWithUntilItIsSet(): void
    {
        $mail = $this->decode($this->get('/api/admin/settings/config'))['groups']['mail'];

        self::assertSame(['none', '', ''], [$mail['transport'], $mail['from_address'], $mail['from_name']]);
        self::assertSame(['smtp', 'native', 'resend'], array_column($mail['drivers'], 'key'));
        self::assertSame(['host', 'port', 'encryption', 'username', 'password'], array_column($mail['drivers'][0]['fields'], 'name'));
        self::assertSame(['host', 'port', 'encryption', 'username'], $mail['drivers'][0]['fields'][4]['bound_to'], 'a kept SMTP password stays with its account');
        self::assertSame(Smtp::PASSWORD_MOVED, $mail['drivers'][0]['fields'][4]['moved']);
        self::assertSame([[], ['resend_key']], [array_column($mail['drivers'][1]['fields'], 'name'), array_column($mail['drivers'][2]['fields'], 'name')], "the host's own mail asks nothing");
        self::assertNotSame('', $mail['drivers'][2]['traits']['sender'], 'who each can send from, in words');
        self::assertSame([
            'smtp' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => ['set' => false, 'hint' => '']],
            'native' => [],
            'resend' => ['resend_key' => ['set' => false, 'hint' => '']],
        ], $mail['values']);
    }

    public function testResendIsChosenWithItsKeyWhichNeverComesBack(): void
    {
        $resend = ['transport' => 'resend', 'resend_key' => 're_test_1234567890abcdef', 'from_address' => 'no-reply@shop.example'];

        $missing = $this->putJson('/api/admin/settings/config/mail', ['resend_key' => ''] + $resend);
        self::assertSame([422, ['resend_key' => [Resend::KEY_MISSING]]], [$missing->getStatusCode(), $this->decode($missing)['errors']], 'Resend needs its key');
        $wrong = $this->putJson('/api/admin/settings/config/mail', ['resend_key' => 'sk_live_not-resends'] + $resend);
        self::assertSame(['resend_key'], array_keys($this->decode($wrong)['errors']), 'a key of another service is not taken for one of Resend');
        self::assertNull($this->file->get('MAIL_TRANSPORT'), 'nothing written');

        $response = $this->putJson('/api/admin/settings/config/mail', $resend);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['resend', 're_test_1234567890abcdef'], [$this->file->get('MAIL_TRANSPORT'), $this->file->get('MAIL_RESEND_API_KEY')]);
        self::assertSame(['set' => true, 'hint' => '••••••cdef'], $this->decode($response)['groups']['mail']['values']['resend']['resend_key'], 'its last characters, never the key');
        self::assertStringNotContainsString('1234567890', (string) $response->getBody());

        self::assertSame(200, $this->putJson('/api/admin/settings/config/mail', ['resend_key' => ''] + $resend)->getStatusCode());
        self::assertSame('re_test_1234567890abcdef', $this->file->get('MAIL_RESEND_API_KEY'), 'left blank, the one kept stays');
        $cleared = $this->putJson('/api/admin/settings/config/mail', ['resend_key' => '', 'clear_resend_key' => true] + $resend);
        self::assertSame([Resend::KEY_MISSING], $this->decode($cleared)['errors']['resend_key'] ?? null, 'cleared while Resend is the way out: no email could go');

        self::assertSame(200, $this->putJson('/api/admin/settings/config/mail', ['transport' => 'native', 'from_address' => 'no-reply@shop.example'])->getStatusCode());
        self::assertSame(['native', 're_test_1234567890abcdef'], [$this->file->get('MAIL_TRANSPORT'), $this->file->get('MAIL_RESEND_API_KEY')], "another way out leaves Resend's key as it is kept, for a way back");
    }

    public function testTheGroupIsSavedWholeAndThePasswordNeverComesBack(): void
    {
        $response = $this->putJson('/api/admin/settings/config/mail', self::SMTP);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            ['smtp', 'mail.example.com', 465, 'ssl', 'no-reply@example.com', 'smtp p@ss/1', 'no-reply@example.com', 'فروشگاه امو'],
            array_map(fn(string $key): mixed => $this->file->get($key), ['MAIL_TRANSPORT', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME']),
            'the port a number, the address in lower case',
        );
        self::assertSame(['set' => true, 'hint' => '••••••••'], $this->decode($response)['groups']['mail']['values']['smtp']['password'], 'a password\'s hint gives none of it away');
        self::assertStringNotContainsString('p@ss', (string) $response->getBody());

        $kept = $this->putJson('/api/admin/settings/config/mail', ['password' => ''] + self::SMTP);
        self::assertSame(200, $kept->getStatusCode());
        self::assertSame('smtp p@ss/1', $this->file->get('MAIL_PASSWORD'), 'left blank, the one kept stays');

        $this->putJson('/api/admin/settings/config/mail', ['clear_password' => true] + self::SMTP);
        self::assertSame('', $this->file->get('MAIL_PASSWORD'));

        $off = $this->putJson('/api/admin/settings/config/mail', ['transport' => 'none']);
        self::assertSame(200, $off->getStatusCode(), 'no email going out needs neither a server nor an address');
        self::assertSame(['none', 'mail.example.com', 'no-reply@example.com'], [$this->file->get('MAIL_TRANSPORT'), $this->file->get('MAIL_HOST'), $this->file->get('MAIL_FROM_ADDRESS')], 'what is kept stays, for a way back');
        self::assertSame('mail.example.com', $this->decode($off)['groups']['mail']['values']['smtp']['host'], 'and shows');
    }

    public function testAKeptPasswordGoesToNoOtherServerNorAccount(): void
    {
        $this->putJson('/api/admin/settings/config/mail', self::SMTP);

        // The server, the account — and how it is reached: a password kept for SSL never goes in clear text by itself.
        foreach (['host' => 'smtp.elsewhere.example', 'port' => 587, 'username' => 'other@example.com', 'encryption' => 'none'] as $field => $moved) {
            $response = $this->putJson('/api/admin/settings/config/mail', [$field => $moved, 'password' => ''] + self::SMTP);
            self::assertSame([422, ['password' => [Smtp::PASSWORD_MOVED]]], [$response->getStatusCode(), $this->decode($response)['errors']], "{$field} moved");
        }
        self::assertSame(['mail.example.com', 'smtp p@ss/1'], [$this->file->get('MAIL_HOST'), $this->file->get('MAIL_PASSWORD')], 'nothing written');

        self::assertSame(200, $this->putJson('/api/admin/settings/config/mail', ['transport' => 'native', 'from_address' => 'no-reply@example.com'])->getStatusCode());
        $back = $this->putJson('/api/admin/settings/config/mail', ['host' => 'smtp.elsewhere.example', 'password' => ''] + self::SMTP);
        self::assertSame([Smtp::PASSWORD_MOVED], $this->decode($back)['errors']['password'] ?? null, 'kept through another way out, it still goes nowhere new');

        self::assertSame(200, $this->putJson('/api/admin/settings/config/mail', ['port' => 465, 'password' => ''] + self::SMTP)->getStatusCode(), 'the same server, however its port is written: the one kept stays');
        self::assertSame(200, $this->putJson('/api/admin/settings/config/mail', ['host' => 'smtp.elsewhere.example', 'password' => 'another p@ss'] + self::SMTP)->getStatusCode(), 'typed again, it goes with the server it is typed for');
        self::assertSame(['smtp.elsewhere.example', 'another p@ss'], [$this->file->get('MAIL_HOST'), $this->file->get('MAIL_PASSWORD')]);
        self::assertSame(200, $this->putJson('/api/admin/settings/config/mail', ['host' => 'mail.example.com', 'password' => '', 'clear_password' => true] + self::SMTP)->getStatusCode(), 'or cleared');
        self::assertSame(['mail.example.com', ''], [$this->file->get('MAIL_HOST'), $this->file->get('MAIL_PASSWORD')]);
    }

    public function testEveryRefusalIsSaidAtOnceAndNothingIsKept(): void
    {
        $response = $this->putJson('/api/admin/settings/config/mail', ['transport' => 'smtp', 'host' => 'https://mail.example.com:587', 'port' => '70000', 'encryption' => 'tls', 'from_address' => 'shop at example']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'host' => ['سرور SMTP را مثل mail.example.com بنویسید؛ بدون http:// و بدون پورت.'],
            'port' => ['پورت SMTP باید عددی بین 1 تا 65535 باشد.'],
            'from_address' => ['ایمیل فرستنده درست نیست؛ آن را مثل name@example.com بنویسید.'],
        ], $this->decode($response)['errors']);
        self::assertNull($this->file->get('MAIL_TRANSPORT'), 'nothing written');

        $missing = $this->unchecked()->putJson('/api/admin/settings/config/mail', ['transport' => 'smtp', 'port' => 587, 'encryption' => 'tls']);
        self::assertSame(['host' => ['سرور SMTP را وارد کنید.'], 'from_address' => ['ایمیل فرستنده را وارد کنید.']], $this->decode($missing)['errors'], 'SMTP needs its server, and any email an address to come from');

        $native = $this->unchecked()->putJson('/api/admin/settings/config/mail', ['transport' => 'native']);
        self::assertSame(['from_address' => ['ایمیل فرستنده را وارد کنید.']], $this->decode($native)['errors'], 'the host\'s own mail needs no server');

        $unknown = $this->unchecked()->putJson('/api/admin/settings/config/mail', ['transport' => 'carrier-pigeon', 'port' => 587, 'encryption' => 'starttls']);
        self::assertSame(['transport'], array_keys($this->decode($unknown)['errors']), 'no driver of that name: nothing else to check');
    }

    public function testATestEmailGoesByTheSettingsAsSaved(): void
    {
        $mail = $this->mail();

        $response = $this->postJson('/api/admin/settings/config/mail/test', ['to' => ' Owner@Example.com ']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['sent' => true], $this->decode($response));
        $sent = $mail->sent();
        self::assertCount(1, $sent);
        self::assertSame(['owner@example.com'], array_map(static fn($address): string => $address->getAddress(), $sent[0]->getTo()));
        self::assertSame([self::MAIL_FROM, 'AmoBot'], [$sent[0]->getFrom()[0]->getAddress(), $sent[0]->getFrom()[0]->getName()], "from MAIL_FROM_ADDRESS, by the shop's name while MAIL_FROM_NAME is empty");
        self::assertSame('ایمیل تست AmoBot', $sent[0]->getSubject());
        self::assertStringContainsString('dir="rtl"', (string) $sent[0]->getHtmlBody(), 'Persian, right to left');
        self::assertStringContainsString('ارسال ایمیل کار می‌کند', (string) $sent[0]->getTextBody(), 'and the same words as plain text');
    }

    public function testThePanelSaysWhyNoTestEmailWent(): void
    {
        $notReady = $this->postJson('/api/admin/settings/config/mail/test', ['to' => 'owner@example.com']);
        self::assertSame([422, MailNotReadyException::MESSAGE], [$notReady->getStatusCode(), $this->decode($notReady)['message']], 'no address to send from saved');

        $this->mail();
        $nowhere = $this->postJson('/api/admin/settings/config/mail/test', ['to' => 'owner']);
        self::assertSame(422, $nowhere->getStatusCode());
        self::assertSame(['to' => ['ایمیل گیرنده درست نیست؛ آن را مثل name@example.com بنویسید.']], $this->decode($nowhere)['errors']);
    }

    public function testAMailServerThatRefusesIsQuotedWithoutItsPassword(): void
    {
        $this->config(['mail.settings' => ['MAIL_PASSWORD' => 'smtp-pass-1']]);
        $this->mail()->failing('Failed to authenticate on SMTP server: 535 Authentication failed for no-reply@example.com:smtp-pass-1');
        $logs = $this->logs();

        $response = $this->postJson('/api/admin/settings/config/mail/test', ['to' => 'owner@example.com']);

        self::assertSame(502, $response->getStatusCode());
        $message = $this->decode($response)['message'];
        self::assertStringStartsWith('ایمیل فرستاده نشد: Failed to authenticate on SMTP server: 535 Authentication failed', $message, "the owner's diagnosis");
        self::assertStringNotContainsString('smtp-pass-1', $message);
        self::assertNotSame(MailFailedException::MESSAGE, $message, "a customer's words say less");
        self::assertCount(1, array_filter($logs->getRecords(), static fn($record): bool => str_contains($record->message, 'did not go')), 'logged once');
        self::assertStringNotContainsString('smtp-pass-1', (string) json_encode(array_map(static fn($record): array => $record->context, $logs->getRecords())));
    }
}
