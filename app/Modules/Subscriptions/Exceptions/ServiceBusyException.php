<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * Another change of the same service is under way on its panel (a renewal, a grant, a move…): it did not end within the
 * wait, or it took over the service from this change, which had stalled past its lease. This change was not made (at
 * most its first step, a counters reset, which the next try reads back); retrying a moment later works on what the other
 * left. A change the panel took is never this: it is recorded regardless (ProvisioningService::record()). A 409.
 */
final class ServiceBusyException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('این سرویس همین حالا در حال تغییر است؛ چند لحظه بعد دوباره تلاش کنید.');
    }

    public function status(): int
    {
        return 409;
    }
}
