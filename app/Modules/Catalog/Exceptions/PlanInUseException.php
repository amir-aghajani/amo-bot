<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A plan that orders or subscriptions still point at cannot be deleted; it can only be
 * deactivated, so the history keeps its name and figures.
 */
final class PlanInUseException extends DomainRuleException
{
    public function status(): int
    {
        return 409;
    }
}
