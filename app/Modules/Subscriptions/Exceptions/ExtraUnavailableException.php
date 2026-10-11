<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * Extra traffic or time paid for a service that can no longer take it at delivery (CustomerExtras::problem(), by the
 * panel's numbers then): switched off since, or ended on the other count. The order fails with the reason, for support
 * to refund or retry.
 */
final class ExtraUnavailableException extends DomainRuleException
{
    protected function field(): string
    {
        return 'status';
    }
}
