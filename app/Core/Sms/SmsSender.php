<?php

declare(strict_types=1);

namespace App\Core\Sms;

/**
 * How a one-time code reaches a phone by SMS, as the configured SMS driver sends it (IPPanel's pattern sending first):
 * the code only — the words around it are the provider's approved pattern, set up on its panel.
 */
interface SmsSender
{
    /**
     * Send `$code` to `$to`.
     *
     * @param string $to The number in E.164 ("+989121234567")
     * @param string $code The one-time code, digits only
     * @throws SmsFailedException when the provider did not take it
     */
    public function sendCode(string $to, string $code): void;
}
