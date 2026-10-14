<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A reward that cannot be given now — no running service takes it, no server delivers its plan, or an agent's shop whose
 * selling is suspended gives no service. Nothing was given.
 */
final class RewardUnavailableException extends DomainRuleException
{
    public const NO_SERVICE = 'سرویس فعالی ندارید که این جایزه به آن اضافه شود.';
    public const NO_SERVER = 'این سرویس در حال حاضر قابل تحویل نیست؛ با پشتیبانی در تماس باشید.';
    public const SUSPENDED = 'این جایزه فعلا داده نمی‌شود؛ برای اطلاعات بیشتر با پشتیبانی در تماس باشید.';
}
