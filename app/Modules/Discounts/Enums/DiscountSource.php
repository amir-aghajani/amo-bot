<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Enums;

/**
 * Where a discount came from: one of the automatic ones (a sale, the customer's own percent, their loyalty tier's — the
 * largest applies), or the code they typed (applied after it).
 */
enum DiscountSource: string
{
    case Sale = 'sale';
    case Personal = 'personal';
    case Tier = 'tier';
    case Code = 'code';
}
