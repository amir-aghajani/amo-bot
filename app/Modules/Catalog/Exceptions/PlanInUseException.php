<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A plan orders or services point at cannot be deleted (409): switch it off, so the history keeps it. */
final class PlanInUseException extends DomainRuleException
{
    public function status(): int
    {
        return 409;
    }
}
