<?php

declare(strict_types=1);

namespace App\Modules\Agency\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A level agents are on cannot be deleted: the agents would lose their discount without anyone deciding so. They
 * move to another level first.
 */
final class LevelInUseException extends DomainRuleException
{
    public function status(): int
    {
        return 409;
    }
}
