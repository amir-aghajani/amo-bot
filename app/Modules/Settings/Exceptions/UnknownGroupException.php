<?php

declare(strict_types=1);

namespace App\Modules\Settings\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A settings group the screen does not have — in this shop, or at all. */
final class UnknownGroupException extends DomainRuleException
{
    public function __construct()
    {
        parent::__construct('این گروه از تنظیمات وجود ندارد.');
    }

    public function status(): int
    {
        return 404;
    }
}
