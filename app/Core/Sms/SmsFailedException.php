<?php

declare(strict_types=1);

namespace App\Core\Sms;

use App\Core\Exceptions\DomainRuleException;

/**
 * An SMS the provider did not take: a 502 telling a customer only that the code did not go. Why (`reason`, the
 * provider's redacted words) is logged, and shown to the owner's test send through explained().
 */
final class SmsFailedException extends DomainRuleException
{
    /** The customer's message: the code did not go, try again. */
    public const MESSAGE = 'پیامک فرستاده نشد؛ کمی بعد دوباره امتحان کنید.';

    private function __construct(
        string $message,
        /** Why, in the provider's words. */
        public readonly string $reason,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self(self::MESSAGE, $reason, $previous);
    }

    /** The same failure as the owner reads it: what the provider said. */
    public function explained(): self
    {
        return new self('پیامک فرستاده نشد: ' . $this->reason, $this->reason, $this);
    }

    public function status(): int
    {
        return 502;
    }
}
