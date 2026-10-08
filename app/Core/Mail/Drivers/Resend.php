<?php

declare(strict_types=1);

namespace App\Core\Mail\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Fields\Secret;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Core\Mail\MailDriver;
use App\Core\Mail\MailSettingsException;
use App\Core\Mail\ResendTransport;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Resend's API (resend.com) — for a host that sends no mail of its own, or whose mail lands in spam: the account's API
 * key (MAIL_RESEND_API_KEY), each email posted through the shop's one outgoing client (ResendTransport), from an
 * address of a domain the account verified.
 */
final class Resend implements MailDriver
{
    /** Resend chosen without its key: no email could go. */
    public const KEY_MISSING = 'برای ارسال با Resend، کلید API آن را وارد کنید.';

    public function __construct(private readonly ClientInterface $http) {}

    public function key(): string
    {
        return 'resend';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'Resend',
            description: 'سرویس ارسال ایمیل Resend، برای هاستی که ایمیل نمی‌فرستد یا ایمیلش به اسپم می‌رود: کلید API را در resend.com › API Keys بسازید و دامنه ایمیل فرستنده را در Resend › Domains تایید کنید.',
            form: new Form($this->key(), [
                new Secret(
                    'resend_key',
                    'MAIL_RESEND_API_KEY',
                    '',
                    pattern: '/^re_[A-Za-z0-9_-]{8,200}$/',
                    mismatch: 'کلید API سرویس Resend را همان‌طور که resend.com › API Keys نشان می‌دهد کپی کنید؛ با re_ شروع می‌شود.',
                    missing: self::KEY_MISSING,
                    spec: new FieldSpec('کلید API', hint: 'در resend.com › API Keys بسازید؛ با re_ شروع می‌شود.'),
                ),
            ]),
            traits: [self::SENDER => 'آدرسی روی دامنه‌ای که در Resend › Domains تایید کرده‌اید.'],
        );
    }

    public function transport(array $values): TransportInterface
    {
        $key = is_string($values['resend_key'] ?? null) ? trim($values['resend_key']) : '';
        if ($key === '') {
            throw new MailSettingsException('MAIL_TRANSPORT resend has no MAIL_RESEND_API_KEY');
        }

        return new ResendTransport($this->http, $key);
    }
}
