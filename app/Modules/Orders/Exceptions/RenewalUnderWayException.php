<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Subscriptions\Models\Subscription;

/**
 * A renewal was asked for while another renewal of the service is under way (OrderService::renewalUnderWay()): the
 * customer would pay twice. Nothing is ordered.
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
