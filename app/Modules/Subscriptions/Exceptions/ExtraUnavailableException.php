<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * Extra traffic, time or devices paid for a service that can no longer take it at delivery (CustomerExtras::refusal(),
 * by the panel's numbers then): switched off since, ended on the other count, or moved to a panel without an IP limit.
 * The order fails with the reason, for support to refund or retry.
 */
final class ExtraUnavailableException extends DomainRuleException
{
    protected function field(): string
    {
        return 'status';
    }
}
