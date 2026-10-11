<?php

declare(strict_types=1);

namespace App\Modules\Discounts\Enums;

/** How a discount code or a sale stands now, for the panels' badge (`used_up`: a code only). */
enum CodeState: string
{
    case Active = 'active';
    case Off = 'off';
    case Scheduled = 'scheduled';
    case Ended = 'ended';
    case UsedUp = 'used_up';
}
