<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Core\Sms\SmsFailedException;
use App\Core\Sms\SmsSender;

/**
 * The tests' SMS provider (the container's `sms.sender` from the boot on): every code it was asked to send, by number,
 * and a refusal to send when told to (refuse()). TestCase::sms() sets SMS up around it.
 */
final class RecordingSmsSender implements SmsSender
{
    /** @var list<array{to: string, code: string}> */
    public array $sent = [];

    private ?string $refusal = null;

    public function sendCode(string $to, string $code): void
    {
        if ($this->refusal !== null) {
            throw SmsFailedException::because($this->refusal);
        }

        $this->sent[] = ['to' => $to, 'code' => $code];
    }

    /** The provider refuses every send from now on, with `$reason`; null takes them again. */
    public function refuse(?string $reason = 'insufficient credit'): void
    {
        $this->refusal = $reason;
    }

    /** The last code sent to `$to`; null when none was. */
    public function lastCodeTo(string $to): ?string
    {
        foreach (array_reverse($this->sent) as $sent) {
            if ($sent['to'] === $to) {
                return $sent['code'];
            }
        }

        return null;
    }

    /** Forget what was sent and take sends again. */
    public function reset(): void
    {
        $this->sent = [];
        $this->refusal = null;
    }
}
