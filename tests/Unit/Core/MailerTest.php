<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Config\Repository as Config;
use App\Core\Drivers\Registry;
use App\Core\Mail\Drivers\Smtp;
use App\Core\Mail\Mailer;
use App\Core\Mail\MailFailedException;
use App\Core\Mail\MailMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Fakes\RecordingMailTransport;

/**
 * The shop's one way to send an email, on a mail server of the tests' own: to the address it was handed and no other —
 * one that reads as a name and an address in angle brackets is that whole address, never the one in the brackets —,
 * and a mail server's refusal said without the secrets the mail drivers keep.
 */
final class MailerTest extends TestCase
{
    public function testAnEmailGoesToItsAddressAloneNeverToOneInsideIt(): void
    {
        $server = new RecordingMailTransport();

        self::mailer($server)->send(new MailMessage('"a<victim@example.com>"@shop.example', 'کد', '<p>۱۲۳</p>', '۱۲۳'));

        self::assertSame(['"a<victim@example.com>"@shop.example'], array_map(static fn($address): string => $address->getAddress(), $server->sent()[0]->getTo()), 'the address that proved itself, as it is');
        self::assertSame([], $server->to('victim@example.com'));
    }

    public function testAnAddressItCannotWriteToIsAnEmailThatDidNotGo(): void
    {
        $this->expectException(MailFailedException::class);

        self::mailer(new RecordingMailTransport())->send(new MailMessage('Victim <victim@example.com>', 'کد', '<p>۱۲۳</p>', '۱۲۳'));
    }

    public function testAMailServersWordsLoseTheSecretsTheDriversKeep(): void
    {
        $server = new RecordingMailTransport();
        $server->failing('535 Authentication failed for no-reply@example.com:smtp-pass-1');

        try {
            self::mailer($server, ['MAIL_PASSWORD' => 'smtp-pass-1', 'MAIL_USERNAME' => 'no-reply@example.com'])->send(new MailMessage('owner@example.com', 'تست', '<p>تست</p>', 'تست'));
            self::fail('the server refused it');
        } catch (MailFailedException $e) {
            self::assertSame('535 Authentication failed for no-reply@example.com:••••', $e->reason, 'the username is no secret');
        }
    }

    /** @param array<string, string> $settings config.php's MAIL_* settings */
    private static function mailer(RecordingMailTransport $server, array $settings = []): Mailer
    {
        return new Mailer($server, new Config(['mail' => ['from_address' => 'shop@example.com', 'from_name' => '', 'settings' => $settings], 'app' => ['name' => 'AmoBot']]), new Registry([new Smtp()]), new NullLogger());
    }
}
