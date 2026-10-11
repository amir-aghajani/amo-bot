<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A reward that cannot be given now — no running service takes it, or no server delivers its plan. Nothing was given. */
final class RewardUnavailableException extends DomainRuleException
{
    public const NO_SERVICE = 'سرویس فعالی ندارید که این جایزه به آن اضافه شود.';
    public const NO_SERVER = 'این سرویس در حال حاضر قابل تحویل نیست؛ با پشتیبانی در تماس باشید.';
}
