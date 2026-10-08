<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A request of the website would leave its customer more unpaid orders open than OrderService::UNPAID_MAX — each one a
 * payment that takes a receipt —: a new one is made once they paid one, or let it expire (ExpireOrdersTask).
 */
final class TooManyUnpaidOrdersException extends DomainRuleException
{
    public const MESSAGE = 'سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
