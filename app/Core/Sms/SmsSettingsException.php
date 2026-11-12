<?php

declare(strict_types=1);

namespace App\Core\Sms;

/** SMS_* settings no sender can be built from (a hand-edited config.php): no code goes out, and the log says why. */
final class SmsSettingsException extends \RuntimeException {}
