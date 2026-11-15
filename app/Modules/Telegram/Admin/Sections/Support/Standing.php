<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Support\Money;

/**
 * What the dashboard warns of in an agent's shop, the panel's words (components/agency/agency-standing.tsx,
 * traffic-shortage.tsx): their terms of selling — a suspension, their agency's end or a short month near — said to the
 * agent of their own bot or to the owner of the agent's, and a bot whose traffic sells nothing. HTML.
 */
final class Standing
{
    /** What a shop whose selling is suspended takes no more of (the panel's SUSPENDED_SALES). */
    public const SUSPENDED_SALES = 'خرید، تمدید، حجم، زمان و کاربر اضافه، شارژ کیف پول و اکانت تست';

    /** Why an agent's selling is suspended (SellingSuspension::words()). */
    private const SUSPENSION = [
        'expired' => 'تاریخ نمایندگی گذشته است',
        'minimum' => 'خرید حجم ماه قبل به کف خرید ماهانه نرسید',
        'manual' => 'فروشگاه اصلی فروش را متوقف کرده است',
    ];

    /** Where an agent buys traffic (the agent panel's BUY_TRAFFIC). */
    public const BUY_TRAFFIC = 'خرید حجم: ربات اصلی، «نمایندگی» ← «خرید حجم»';

    /**
     * What of the terms needs an eye now: `suspended`, `expiring` (the end within the warned days), `short` (a judged month
     * below its minimum, near its end); null for nothing.
     *
     * @param array<string, mixed>|null $standing
     */
    public static function concern(?array $standing): ?string
    {
        if ($standing === null) {
            return null;
        }
        $warn = (int) ($standing['warn_days'] ?? 0);
        $expires = is_string($standing['expires_at'] ?? null) ? $standing['expires_at'] : null;

        return match (true) {
            is_array($standing['suspension'] ?? null) => 'suspended',
            $expires !== null && Words::daysUntil($expires) <= $warn => 'expiring',
            self::short($standing) && Words::daysUntil(self::month($standing)['ends_at']) <= $warn => 'short',
            default => null,
        };
    }

    /**
     * The agent's notice of their own bot (the panel's SellingNotice); null while all is well.
     *
     * @param array<string, mixed>|null $standing
     */
    public static function toAgent(?array $standing): ?string
    {
        $concern = self::concern($standing);
        if ($standing === null || $concern === null) {
            return null;
        }
        $month = self::month($standing);
        if ($concern === 'suspended') {
            $reason = self::reason($standing);

            return '⛔️ <b>فروش ربات شما متوقف است: ' . Fmt::e(self::SUSPENSION[$reason] ?? $reason) . '.</b> ربات، پنل و سرویس‌های فعلی مشتری‌ها کار می‌کنند، ولی ' . self::SUSPENDED_SALES . ' تازه انجام نمی‌شود. '
                . match ($reason) {
                    'minimum' => 'با خرید حجم در ربات اصلی تا رسیدن خرید ' . Fmt::e($month['name']) . ' به کف (' . self::monthWords($standing) . ')، فروش خودکار باز می‌شود.',
                    'expired' => 'برای تمدید نمایندگی با پشتیبانی فروشگاه اصلی تماس بگیرید.',
                    default => 'فقط پشتیبانی فروشگاه اصلی آن را دوباره باز می‌کند.',
                };
        }
        if ($concern === 'expiring') {
            $expires = (string) $standing['expires_at'];

            return '📅 <b>نمایندگی شما ' . Fmt::date($expires) . ' به پایان می‌رسد (' . Words::left($expires) . ').</b> از آن به بعد ربات شما فروش تازه‌ای ندارد؛ برای تمدید با پشتیبانی فروشگاه اصلی تماس بگیرید.';
        }

        return '📉 <b>تا پایان ' . Fmt::e($month['name']) . ' ' . Fmt::number(Words::daysUntil($month['ends_at'])) . ' روز مانده و خرید حجم این ماه ' . self::monthWords($standing) . ' است.</b> اگر تا پایان ماه به کف نرسد، فروش ربات شما متوقف می‌شود.';
    }

    /**
     * The owner's notice of the agent whose shop is open (the panel's OwnerSellingNotice), with where to see to it; null
     * while all is well.
     *
     * @param array<string, mixed>|null $standing
     */
    public static function toOwner(?array $standing): ?string
    {
        $concern = self::concern($standing);
        if ($standing === null || $concern === null) {
            return null;
        }
        $month = self::month($standing);
        $help = ' از «نمایندگان» به آن رسیدگی کنید.';
        if ($concern === 'suspended') {
            $reason = self::reason($standing);

            return '⛔️ <b>فروش ربات این نماینده متوقف است:</b> ' . Fmt::e(self::SUSPENSION[$reason] ?? $reason) . '. ربات، پنل و سرویس‌های فعلی مشتری‌هایش کار می‌کنند.' . $help;
        }
        if ($concern === 'expiring') {
            return '📅 <b>پایان نمایندگی این نماینده نزدیک است.</b> نمایندگی ' . Fmt::date((string) $standing['expires_at']) . ' به پایان می‌رسد؛ بعد از آن فروش رباتش متوقف می‌شود.' . $help;
        }

        return '📉 <b>خرید حجم این نماینده در این ماه هنوز به کف نرسیده است.</b> خرید حجم ' . Fmt::e($month['name']) . ': ' . self::monthWords($standing) . '. اگر تا پایان ماه به کف نرسد، فروش رباتش متوقف می‌شود.' . $help;
    }

    /**
     * A bot whose traffic sells nothing (the panel's TrafficShortageNotice), and what to do about it (`$help`, HTML).
     *
     * @param array<string, mixed> $shortage
     */
    public static function shortage(array $shortage, string $help): string
    {
        $balance = (int) ($shortage['balance'] ?? 0);
        $smallest = is_int($shortage['smallest_plan'] ?? null) ? $shortage['smallest_plan'] : null;
        $lead = $balance > 0 && $smallest !== null
            ? 'حجم باقی‌مانده ربات (' . Fmt::bytes($balance) . ') برای کوچک‌ترین پلن فروشی (' . Fmt::bytes($smallest) . ') کافی نیست.'
            : 'حجم ربات تمام شده است.';

        return '🪫 <b>' . $lead . '</b> تا حجم تازه نرسد، ربات هیچ پلنی نمی‌فروشد و سرویسی را تمدید نمی‌کند.' . ($help === '' ? '' : ' ' . $help);
    }

    /**
     * The terms as facts (the panel's StandingFacts): the end, this month's purchases against the minimum, the selling.
     *
     * @param array<string, mixed> $standing
     * @return array<string, string>
     */
    public static function facts(array $standing): array
    {
        $expires = is_string($standing['expires_at'] ?? null) ? $standing['expires_at'] : null;
        $month = self::month($standing);

        return [
            'پایان نمایندگی' => $expires === null ? 'بدون پایان' : Fmt::date($expires) . ' (' . Words::left($expires) . ')',
            'خرید حجم ' . $month['name'] => is_numeric($standing['monthly_minimum'] ?? null)
                ? self::monthWords($standing) . ($month['judged'] ? '' : ' (این ماه سنجیده نمی‌شود)')
                : Fmt::money($month['purchased']) . ' · بدون کف ماهانه',
            'فروش' => is_array($standing['suspension'] ?? null) ? 'متوقف: ' . Fmt::e(self::SUSPENSION[self::reason($standing)] ?? self::reason($standing)) : 'باز',
        ];
    }

    /**
     * Whether the month's purchases fall short of the minimum while the month is judged.
     *
     * @param array<string, mixed> $standing
     */
    private static function short(array $standing): bool
    {
        $minimum = $standing['monthly_minimum'] ?? null;
        $month = self::month($standing);

        return is_numeric($minimum) && $month['judged'] && Money::compare($month['purchased'], (string) $minimum) < 0;
    }

    /**
     * «۲۵۰٬۰۰۰ تومان از ۵۰۰٬۰۰۰ تومان» — this month's purchases against the minimum.
     *
     * @param array<string, mixed> $standing
     */
    private static function monthWords(array $standing): string
    {
        $minimum = $standing['monthly_minimum'] ?? null;

        return Fmt::money(self::month($standing)['purchased']) . ' از ' . Fmt::money(is_numeric($minimum) ? (string) $minimum : null);
    }

    /**
     * @param array<string, mixed> $standing
     * @return array{name: string, purchased: string, ends_at: string|null, judged: bool}
     */
    private static function month(array $standing): array
    {
        $month = Json::part($standing, 'month');

        return [
            'name' => is_string($month['name'] ?? null) ? $month['name'] : '',
            'purchased' => is_numeric($month['purchased'] ?? null) ? (string) $month['purchased'] : '0',
            'ends_at' => is_string($month['ends_at'] ?? null) ? $month['ends_at'] : null,
            'judged' => Json::on($month, 'judged'),
        ];
    }

    /** @param array<string, mixed> $standing */
    private static function reason(array $standing): string
    {
        $suspension = Json::part($standing, 'suspension');

        return is_string($suspension['reason'] ?? null) ? $suspension['reason'] : 'manual';
    }
}
