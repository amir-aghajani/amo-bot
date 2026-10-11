<?php

declare(strict_types=1);

namespace App\Core\Mail;

/** MAIL_* settings no transport can be built from (a hand-edited config.php): no email goes out, and the log says why. */
final class MailSettingsException extends \RuntimeException {}
