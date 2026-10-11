<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Providers\Exceptions\ProviderException;

/**
 * A customer's screen asked for their service as its panel has it now (ProvisioningService::look()) and the panel could
 * not be read: it failed (the previous exception), or it is in backoff (Server::isBackingOff()). The row's copy does not
 * answer that question: a 502 worded for the customer.
 */
final class ServiceNotReadException extends DomainRuleException
{
    public function __construct(?ProviderException $previous = null)
    {
        parent::__construct('اطلاعات این سرویس الان از سرور خوانده نشد؛ کمی بعد دوباره تلاش کنید.', 0, $previous);
    }

    public function status(): int
    {
        return 502;
    }
}
