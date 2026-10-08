<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Drivers\Driver;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * A way the shop's email goes out — an account on a mail server, the host's own mail, an email API (Drivers\*) —:
 * config.php's MAIL_TRANSPORT names it (`none`: no driver, no email), its form is its own MAIL_* settings, and the
 * container's `mail.transport` is the transport it makes of them (MailTransport). Its descriptor says in the `sender`
 * trait which address its emails may come from, in the owner's words.
 */
interface MailDriver extends Driver
{
    /** The trait (Descriptor::$traits) that says which address its emails may come from. */
    public const SENDER = 'sender';

    /**
     * The transport these settings make.
     *
     * @param array<string, mixed> $values Its form's values by field name, as config.php holds them (Form::values())
     * @throws MailSettingsException when they make none — a server or a key missing: no email goes out, and the log says why
     */
    public function transport(array $values): TransportInterface;
}
