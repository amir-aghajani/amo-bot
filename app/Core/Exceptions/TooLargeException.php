<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

/** What was sent is larger than the shop takes, whatever it holds: a 413, refused before anything of it is used. */
final class TooLargeException extends DomainRuleException
{
    public function status(): int
    {
        return 413;
    }
}
