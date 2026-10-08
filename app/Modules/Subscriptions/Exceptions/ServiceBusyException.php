<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * Another change of the same service is under way on its panel (a renewal, a grant, a move…): it did not end within the
 * wait, or it took the service over from this change, which had stalled past its time before its next call to the panel.
 * This change was not made — at most its first step, a counters reset, which its next try reads back —; trying again a
 * moment later works on what the other one left. (A change its panel took is never this: it is recorded, whatever became
 * of its hold — ProvisioningService::record().)
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
