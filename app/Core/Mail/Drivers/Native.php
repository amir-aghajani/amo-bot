<?php

declare(strict_types=1);

namespace App\Core\Mail\Drivers;

use App\Core\Drivers\Descriptor;
use App\Core\Forms\Form;
use App\Core\Mail\MailDriver;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * The host's own mail, as PHP's mail() sends it (php.ini's sendmail_path): nothing to set but who the emails come from —
 * an address of the host's own domain.
 */
final class Native implements MailDriver
{
    public function key(): string
    {
        return 'native';
    }

    public function describe(): Descriptor
    {
        return new Descriptor(
            key: $this->key(),
            label: 'ایمیل خود هاست',
            description: 'ایمیل خود هاست، همان که PHP با sendmail_path در php.ini می‌فرستد؛ هاست باید آن را راه انداخته باشد.',
            form: new Form($this->key(), []),
            traits: [self::SENDER => 'آدرسی روی دامنه خود هاست.'],
        );
    }

    public function transport(array $values): TransportInterface
    {
        return Transport::fromDsn('native://default');
    }
}
