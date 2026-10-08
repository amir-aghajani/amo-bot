<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The customer's «تمدید خودکار» switch, set while the service is not offered it (AutoRenewal::offeredFor()): it does not
 * run, never ends, has lost its plan, or the wallet that pays every automatic renewal is off. Nothing was changed; the
 * bot words it its own way.
 */
final class AutoRenewUnavailableException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('تمدید خودکار برای این سرویس در دسترس نیست.');
    }

    protected function field(): string
    {
        return 'auto_renew';
    }
}
