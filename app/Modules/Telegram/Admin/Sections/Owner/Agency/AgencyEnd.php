<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Agency;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Support\LocalTime;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * An agency's end as the bot asks it and the API takes it: a day and an hour on the shop's clock ("2026-10-12T18:00",
 * LocalTime::MOMENT) — some days from now, or a Jalali day typed («۱۴۰۵/۰۷/۲۰», ending at its last hour, as the panel's
 * field does) —, or none.
 */
final class AgencyEnd
{
    /** What an agency without an end is said as, wherever its end is shown or asked. */
    public const NONE = 'بدون پایان';

    /** The hour a typed day ends at (the panel's: a day's last hour). */
    private const DAY_ENDS = 23;

    /** A moment the API answered (ISO 8601) as the API takes it back; null for none. */
    public static function of(?string $iso): ?string
    {
        $moment = Fmt::moment($iso);

        return $moment === null ? null : LocalTime::of($moment)->format(LocalTime::MOMENT);
    }

    /** `$days` from now, to the hour, on the shop's clock. */
    public static function in(int $days): string
    {
        return LocalTime::of(Carbon::now()->addDays($days))->format('Y-m-d\TH:00');
    }

    /** What was typed: a number of days from now, or a Jalali day; null for neither. */
    public static function typed(string $text): ?string
    {
        $text = trim(Persian::latinDigits($text));
        if (preg_match('/^\d{1,4}$/', $text) === 1 && (int) $text > 0 && strlen($text) < 4) {
            return self::in((int) $text);
        }
        if (preg_match('#^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})$#', $text, $day) !== 1) {
            return null;
        }
        [$year, $month, $date] = [(int) $day[1], (int) $day[2], (int) $day[3]];
        if ($month < 1 || $month > 12 || $date < 1 || $date > ($month <= 6 ? 31 : 30)) {
            return null;
        }
        [$gy, $gm, $gd] = self::gregorian($year, $month, $date);

        return sprintf('%04d-%02d-%02dT%02d:00', $gy, $gm, $gd, self::DAY_ENDS);
    }

    /** The moment as a person reads it: «۲۰ مهر ۱۴۰۵، ۲۳:۰۰»; «بدون پایان» (NONE) for none. */
    public static function words(?string $moment): string
    {
        if ($moment === null || $moment === '') {
            return self::NONE;
        }
        $local = \DateTimeImmutable::createFromFormat(LocalTime::MOMENT, $moment, LocalTime::zone());

        return $local === false ? Fmt::e($moment) : Persian::date($local, true);
    }

    /**
     * Jalali → Gregorian (the standard algorithm, as Persian::jalali()'s reverse).
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function gregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $months = [0, 31, ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0 ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 1;
        while ($gm <= 12 && $gd > $months[$gm]) {
            $gd -= $months[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }
}
