<?php

declare(strict_types=1);

namespace App\Modules\Imports;

/**
 * How long one step of an import may go on: a few seconds, a request's on a shared host or each of the console's steps
 * (seconds()), every row in one go (unlimited()), or a set number of checks, for a test (checks()). A step checks
 * spent() between rows and stops at the first true, saving where it got to.
 */
final class Budget
{
    private int $checked = 0;

    private function __construct(
        private readonly ?float $until,
        private readonly ?int $checks,
    ) {}

    public static function seconds(float $seconds): self
    {
        return new self(microtime(true) + $seconds, null);
    }

    public static function unlimited(): self
    {
        return new self(null, null);
    }

    /** Spent after `$checks` calls of spent() — the row count a test steps by. */
    public static function checks(int $checks): self
    {
        return new self(null, $checks);
    }

    public function spent(): bool
    {
        if ($this->checks !== null) {
            return ++$this->checked > $this->checks;
        }

        return $this->until !== null && microtime(true) >= $this->until;
    }
}
