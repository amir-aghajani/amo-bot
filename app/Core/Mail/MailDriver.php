<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Drivers\Driver;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * A way email goes out (SMTP, the host's mail(), Resend), named by MAIL_TRANSPORT; its form is its own MAIL_* settings.
 * The `sender` trait tells the owner which address its emails may come from.
 */
interface MailDriver extends Driver
{
    /** The trait (Descriptor::$traits) that says which address its emails may come from. */
    public const SENDER = 'sender';

    /**
     * The transport these settings make.
     *
     * @param array<string, mixed> $values Its form's values by field name, as config.php holds them (Form::values())
     * @throws MailSettingsException when a server or a key is missing
     */
    public function transport(array $values): TransportInterface;
}
