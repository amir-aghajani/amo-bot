<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Enums;

/** What a discount code takes off: a percent (up to `max_discount`), fixed Toman, or everything (free). Never more than the amount. */
enum DiscountType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case Free = 'free';
}
