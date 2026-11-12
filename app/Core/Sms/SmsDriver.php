<?php

declare(strict_types=1);

namespace App\Core\Sms;

use App\Core\Drivers\Driver;

/**
 * A way one-time codes go out by SMS (IPPanel's pattern sending), named by SMS_DRIVER; its form is its own SMS_*
 * settings. Its sender may also tell the account's credit (ReportsCredit), which the owner's settings show.
 */
interface SmsDriver extends Driver
{
    /**
     * The sender these settings make.
     *
     * @param array<string, mixed> $values Its form's values by field name, as config.php holds them (Form::values())
     * @throws SmsSettingsException when a key, a sender number or a pattern is missing
     */
    public function sender(array $values): SmsSender;
}
