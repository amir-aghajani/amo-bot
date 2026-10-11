<?php

declare(strict_types=1);

namespace App\Modules\Orders\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A website request would give its customer more than OrderService::UNPAID_MAX unpaid orders (each may hold a receipt picture). */
final class TooManyUnpaidOrdersException extends DomainRuleException
{
    public const MESSAGE = 'سفارش‌های پرداخت‌نشده شما زیاد است؛ اول آن‌ها را پرداخت کنید یا بگذارید منقضی شوند.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
