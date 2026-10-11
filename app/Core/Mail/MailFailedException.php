<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Exceptions\DomainRuleException;

/**
 * An email the transport did not take: a 502 telling a customer only that it did not go. Why (`reason`, the
 * transport's redacted words) is logged, and shown to the owner's test send through explained().
 */
final class MailFailedException extends DomainRuleException
{
    /** The customer's message: the email did not go, try again. */
    public const MESSAGE = 'ایمیل فرستاده نشد؛ کمی بعد دوباره امتحان کنید.';

    private function __construct(
        string $message,
        /** Why, in the transport's words. */
        public readonly string $reason,
        ?\Throwable $previous,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        return new self(self::MESSAGE, $reason, $previous);
    }

    /** The same failure as the owner reads it: what the transport said. */
    public function explained(): self
    {
        return new self('ایمیل فرستاده نشد: ' . $this->reason, $this->reason, $this);
    }

    public function status(): int
    {
        return 502;
    }
}
