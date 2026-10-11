<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Enums;

/** Where a gift code stands, as the panels filter it: unused, redeemed, or expired untaken. */
enum GiftCodeStatus: string
{
    case Unused = 'unused';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
}
