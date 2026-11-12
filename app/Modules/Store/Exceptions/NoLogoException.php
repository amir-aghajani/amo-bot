<?php

declare(strict_types=1);

namespace App\Modules\Store\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** The mini app's logo was asked for while it has none (404): the app shows the shop's initial instead. */
final class NoLogoException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('این مینی‌اپ لوگویی ندارد.');
    }

    public function status(): int
    {
        return 404;
    }
}
