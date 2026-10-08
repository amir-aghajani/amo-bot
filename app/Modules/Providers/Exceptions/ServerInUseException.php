<?php

declare(strict_types=1);

namespace App\Modules\Providers\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A server with services on it — any bot's — is not deleted: they are moved elsewhere or deleted first, or the server is
 * switched off instead. The message is the Persian sentence the admin sees.
 */
final class ServerInUseException extends DomainRuleException
{
    public function status(): int
    {
        return 409;
    }
}
