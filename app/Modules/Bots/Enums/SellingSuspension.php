<?php

declare(strict_types=1);

namespace App\Modules\Bots\Enums;

/**
 * Why an agent's shop sells nothing new (`bots.suspension_reason`) while its bot, its panel and its services run on
 * (Bot::sellingSuspension(), Bot::sells()). The value is stored.
 */
enum SellingSuspension: string
{
    /** Their agency's date passed (Agency\Services\AgencyStanding); the owner extends it. */
    case Expired = 'expired';

    /** A Jalali month ended with less traffic bought than their monthly minimum; buying it lifts it. */
    case Minimum = 'minimum';

    /** The owner suspended it; only the owner lifts it. */
    case Manual = 'manual';

    /**
     * Why the shop sells nothing now, in the one wording every reader gets: the agent in the main bot (a customer of it,
     * so never «مدیر»), the owner's report group, and both panels — whose SELLING_SUSPENSION (lib/statuses.ts) repeats
     * it word for word.
     */
    public function words(): string
    {
        return match ($this) {
            self::Expired => 'تاریخ نمایندگی گذشته است',
            self::Minimum => 'خرید حجم ماه قبل به کف خرید ماهانه نرسید',
            self::Manual => 'فروشگاه اصلی فروش را متوقف کرده است',
        };
    }

    /** What lifts it, in the owner's words: their report group's line after words() (ShopReports::agencySuspended()). */
    public function remedy(): string
    {
        return match ($this) {
            self::Expired => 'با تمدید آن در صفحه «نمایندگان» فروش باز می‌شود',
            self::Minimum => 'با رسیدن خرید این ماه به کف، یا از صفحه «نمایندگان»، فروش باز می‌شود',
            self::Manual => 'فقط از صفحه «نمایندگان» فروش باز می‌شود',
        };
    }
}
