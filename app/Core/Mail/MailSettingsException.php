<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * config.php's MAIL_* settings make no transport — a driver it does not have, a server or a key missing (a file edited by
 * hand: the settings screen refuses them): no email goes out, and the log says why (MailTransport).
 */
final class MailSettingsException extends \RuntimeException {}
