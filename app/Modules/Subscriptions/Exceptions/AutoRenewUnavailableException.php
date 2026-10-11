<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * The «تمدید خودکار» switch set while the service is not offered it (AutoRenewal::offeredFor()): it does not run, never
 * ends, has lost its plan, or the wallet is off. A 422 on `auto_renew`; nothing changed.
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
