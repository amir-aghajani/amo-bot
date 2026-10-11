<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A discount code with uses is kept for its orders' history (409): switch it off instead. */
final class DiscountInUseException extends DomainRuleException
{
    public const MESSAGE = 'این کد تخفیف در سفارش‌ها استفاده شده و حذف نمی‌شود؛ به جای حذف، آن را غیرفعال کنید.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    public function status(): int
    {
        return 409;
    }
}
