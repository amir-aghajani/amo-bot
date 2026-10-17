<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/** A discount code with uses — or orders refunded since — is kept for its orders' history (409): switch it off instead. */
final class DiscountInUseException extends DomainRuleException
{
    public const MESSAGE = 'این کد تخفیف در سفارش‌ها استفاده شده و حذف نمی‌شود؛ به جای حذف، آن را غیرفعال کنید.';

    /** Its only orders were refunded: no use counts (the panels show none), but their history keeps it. */
    public const REFUNDED = 'این کد تخفیف فقط در سفارش‌هایی آمده که بازپرداخت شده‌اند؛ استفاده‌ای از آن حساب نمی‌شود، ولی برای سابقه آن سفارش‌ها می‌ماند و حذف نمی‌شود. به جای حذف، آن را غیرفعال کنید.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }

    public static function refunded(): self
    {
        return new self(self::REFUNDED);
    }

    public function status(): int
    {
        return 409;
    }
}
