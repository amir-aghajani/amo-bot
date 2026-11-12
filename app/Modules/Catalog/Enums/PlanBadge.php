<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * The label a plan's card wears in the mini app and on the website («برچسب»), set by the shop on the plan: what the shop
 * sells most, what it suggests, or its premium plan. A plan has one or none; the screens word it themselves
 * («پرفروش‌ترین», «پیشنهاد ما», «VIP»).
 */
enum PlanBadge: string
{
    case Bestseller = 'bestseller';
    case Recommended = 'recommended';
    case Vip = 'vip';
}
