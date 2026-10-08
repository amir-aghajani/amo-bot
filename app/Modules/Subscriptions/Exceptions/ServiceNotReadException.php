<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Providers\Exceptions\ProviderException;

/**
 * A customer's own screen asked for their service as its panel has it now (ProvisioningService::look()) and the panel
 * could not be read: it failed (the ProviderException is the previous one), or it is one the shop leaves alone a while
 * after it failed (Server::isBackingOff()). The row's copy is no answer to that question: a 502, in the customer's
 * words; the bot words it its own way.
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
