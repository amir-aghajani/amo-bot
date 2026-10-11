<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

/**
 * Telegram refused a Bot API call, or could not be reached (error code 0): its description, its parameters
 * (`retry_after`), and what kind of refusal it is (refusal(), is()).
 */
final class TelegramApiException extends \RuntimeException
{
    private ?Refusal $refusal = null;

    /** @param array<string, mixed> $parameters The "parameters" object Telegram may attach (e.g. retry_after). */
    public function __construct(
        string $message,
        public readonly int $errorCode = 0,
        public readonly array $parameters = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }

    public function refusal(): Refusal
    {
        return $this->refusal ??= Refusal::of($this->errorCode, $this->getMessage());
    }

    /** Whether the refusal is one of these. */
    public function is(Refusal ...$refusals): bool
    {
        return in_array($this->refusal(), $refusals, true);
    }

    /** The seconds a flood limit asks to wait, when Telegram said. */
    public function retryAfter(): ?int
    {
        return isset($this->parameters['retry_after']) ? (int) $this->parameters['retry_after'] : null;
    }
}
