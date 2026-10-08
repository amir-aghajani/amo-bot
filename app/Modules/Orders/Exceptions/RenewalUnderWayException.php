<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A renewal of the service was asked for while another is under way — its receipt with support, or paid and being
 * delivered or waiting for support's retry (OrderService::renewalUnderWay()): the service would be renewed, and its
 * customer charged, twice. Nothing is ordered.
 */
final class RenewalUnderWayException extends DomainRuleException
{
    public function __construct(Subscription $subscription)
    {
        parent::__construct("برای سرویس {$subscription->remote_name} یک تمدید در حال بررسی یا انجام است.");
    }

    protected function field(): string
    {
        return 'status';
    }
}
