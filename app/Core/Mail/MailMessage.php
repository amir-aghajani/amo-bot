<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * One email: recipient, subject, HTML and plain-text bodies. `fromName` is the sender's name when MAIL_FROM_NAME is
 * empty: the shop the email is about (an agent's bot's name).
 */
final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $fromName = null,
    ) {}
}
