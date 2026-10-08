<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * When a client stops working, the three ways a panel can mean it: never, at a fixed deadline, or after a term the panel
 * starts counting at the client's first connection — which becomes a deadline on the panel's side once the customer
 * connects.
 */
final class Expiry
{
    private function __construct(
        private readonly ?\DateTimeImmutable $deadline,
        private readonly ?int $pendingSeconds,
    ) {}

    public static function never(): self
    {
        return new self(null, null);
    }

    public static function at(\DateTimeImmutable $deadline): self
    {
        return new self($deadline, null);
    }

    /** A term the panel starts counting at the first connection. */
    public static function afterFirstUse(int $seconds): self
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('A term counted from the first connection must be positive.');
        }

        return new self(null, $seconds);
    }

    /** The fixed deadline; null for "never" and for a term whose clock has not started. */
    public function deadline(): ?\DateTimeImmutable
    {
        return $this->deadline;
    }

    /** The term still waiting for the first connection, in seconds; null otherwise. */
    public function pendingSeconds(): ?int
    {
        return $this->pendingSeconds;
    }
}
