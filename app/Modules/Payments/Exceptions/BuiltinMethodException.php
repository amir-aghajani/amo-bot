<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Payments\Models\PaymentMethod;

/**
 * The wallet (and any other built-in driver) is part of the shop: its one row can be switched off,
 * never deleted.
 */
final class BuiltinMethodException extends DomainRuleException
{
    public function __construct(PaymentMethod $method)
    {
        parent::__construct("«{$method->label}» روش داخلی فروشگاه است و حذف نمی‌شود؛ می‌توانید آن را غیرفعال کنید.");
    }

    public function status(): int
    {
        return 409;
    }
}
