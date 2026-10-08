<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * One email the shop sends: to whom, about what, its HTML body and the plain text beside it (a reader that shows no HTML
 * reads that). `fromName` is who it comes from in words when config.php names nobody (MAIL_FROM_NAME): the shop the
 * email is about — an agent's shop, its bot's name.
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
