<?php

declare(strict_types=1);

namespace App\Modules\Agency\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A level agents are on cannot be deleted (409): their price per GB would go without anyone deciding so. Move them first. */
final class LevelInUseException extends DomainRuleException
{
    public function status(): int
    {
        return 409;
    }
}
