<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** A request body larger than the shop takes: a 413, before any of it is used. */
final class TooLargeException extends DomainRuleException
{
    public function status(): int
    {
        return 413;
    }
}
