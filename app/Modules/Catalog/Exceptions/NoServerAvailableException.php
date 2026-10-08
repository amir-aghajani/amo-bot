<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The plan cannot be delivered on the server asked for (or on any) right now — on the screen, a refusal of the server picked. */
final class NoServerAvailableException extends DomainRuleException
{
    protected function field(): string
    {
        return 'server_id';
    }
}
