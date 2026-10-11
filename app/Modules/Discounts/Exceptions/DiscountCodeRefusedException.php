<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Exceptions;

use App\Core\Exceptions\DomainRuleException;

/**
 * A discount code that does not hold for what the customer is buying (Services\DiscountCodes), worded for the customer:
 * a 422 on `discount_code`.
 */
final class DiscountCodeRefusedException extends DomainRuleException
{
    public const FIELD = 'discount_code';

    protected function field(): string
    {
        return self::FIELD;
    }
}
