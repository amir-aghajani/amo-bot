<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A server with any bot's services on it is not deleted: they are moved or deleted first, or the server is switched off
 * instead. A 409; the message is the sentence the owner sees.
 */
final class ServerInUseException extends DomainRuleException
{
    public function status(): int
    {
        return 409;
    }
}
