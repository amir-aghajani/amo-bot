<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Exceptions\DomainRuleException;

/**
 * An email the transport did not take — the mail server refused it, or could not be reached —: a 502. Its message is a
 * customer's, saying only that it did not go; why (`reason`, the transport's own words, never a password) is the log's
 * (Mailer logs it once) and the owner's, whose test send shows it (explained()).
 */
final class MailFailedException extends DomainRuleException
{
    /** What a customer is told: the email did not go, and to try again. */
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
