<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Enums;

/** Why a referrer's referrals look made up: many joined in a short while (`burst`), or many joined and none bought (`idle`). */
enum AlertReason: string
{
    case Burst = 'burst';
    case Idle = 'idle';

    /** The reason in the admins' words: «۱۰ زیرمجموعه در ۶۰ دقیقه», «۲۰ زیرمجموعه بدون هیچ خرید». */
    public function words(string $count, string $minutes): string
    {
        return match ($this) {
            self::Burst => "{$count} زیرمجموعه در {$minutes} دقیقه",
            self::Idle => "{$count} زیرمجموعه بدون هیچ خرید",
        };
    }
}
