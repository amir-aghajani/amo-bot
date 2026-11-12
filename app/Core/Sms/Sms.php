<?php

declare(strict_types=1);

namespace App\Core\Sms;

use App\Core\Config\Repository as Config;

/**
 * The installation's SMS, as the rest of the shop asks it: whether codes can go out at all (an SMS driver is set up in
 * config.php's SMS_*, «تنظیمات پنل › پیامک»), whether agents' websites may send them too (SMS_AGENTS: the owner pays
 * for every one), and sending one. The sender is the container's `sms.sender` — built from config.php, or a test's fake.
 */
final class Sms
{
    public function __construct(
        private readonly Config $config,
        private readonly ?SmsSender $sender,
    ) {}

    /** Whether codes can go out: a driver is chosen and its sender built. */
    public function available(): bool
    {
        $driver = trim((string) $this->config->get('sms.driver', 'none'));

        return $this->sender !== null && $driver !== '' && $driver !== 'none';
    }

    /** Whether agents' websites may send codes too, on the owner's account. */
    public function forAgents(): bool
    {
        return $this->available() && filter_var($this->config->get('sms.agents', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * Send a one-time code to a phone.
     *
     * @param string $to The number in E.164
     * @throws SmsFailedException when SMS is not set up, or the provider did not take it
     */
    public function sendCode(string $to, string $code): void
    {
        if (!$this->available() || $this->sender === null) {
            throw SmsFailedException::because('no SMS driver is set up');
        }

        $this->sender->sendCode($to, $code);
    }
}
