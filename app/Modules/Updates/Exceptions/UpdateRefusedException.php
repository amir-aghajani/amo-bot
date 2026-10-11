<?php

declare(strict_types=1);

namespace App\Modules\Updates\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The update cannot go on; the message, worded for the owner, gives the way out: refused() (422) for what the release or host stops,
 * busy() (409) for what another request holds now, unreachable() (502) for GitHub out of reach.
 */
final class UpdateRefusedException extends DomainRuleException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function refused(string $message): self
    {
        return new self($message, 422);
    }

    public static function busy(string $message): self
    {
        return new self($message, 409);
    }

    public static function unreachable(string $message): self
    {
        return new self($message, 502);
    }

    public function status(): int
    {
        return $this->status;
    }
}
