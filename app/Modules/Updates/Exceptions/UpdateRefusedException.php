<?php

declare(strict_types=1);

namespace App\Modules\Updates\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The update cannot go on, in the owner's words and with the way out: refused() for what this release or this host
 * stops (a signature that does not verify, a PHP too old, folders PHP may not change), busy() for what another request
 * holds this moment (a step of the update, the scheduler's run), unreachable() for GitHub out of reach.
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
