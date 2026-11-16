<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Form;

use App\Support\LocalTime;
use App\Support\Persian;
use Illuminate\Support\Carbon;

/**
 * One end of a discount's window as the bot's forms take it: a Jalali day typed as the panel's form takes one
 * («1405/07/20», Persian digits too) and an hour after it if wanted («1405/07/20 18», «… ساعت ۱۸:۳۰»), on the shop's
 * clock — kept in a draft as the panels send it ("2026-10-12T18:00", LocalTime::MOMENT), said back as the bot says dates.
 */
final class Moment
{
    /** What a window's end takes, for its question. */
    public const HINT = 'روز را به تقویم شمسی بنویسید و اگر بخواهید ساعتش را بعدش؛ به وقت فروشگاه.';

    /** A typed day (and hour) as the API takes it; null for text that is no real day. `$hour` stands in for none typed. */
    public static function parse(string $typed, int $hour): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', Persian::latinDigits($typed)) ?? '');
        if (preg_match('~^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})(?: (?:ساعت ?)?(\d{1,2})(?:[:.](\d{2}))?)?$~u', $text, $match) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];
        $hour = ($match[4] ?? '') !== '' ? (int) $match[4] : $hour;
        $minute = ($match[5] ?? '') !== '' ? (int) $match[5] : 0;
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59) {
            return null;
        }
        [$gy, $gm, $gd] = self::gregorian($year, $month, $day);
        $noon = (new \DateTimeImmutable('now', LocalTime::zone()))->setDate($gy, $gm, $gd)->setTime(12, 0);
        // A day past its month's end (1404/12/30 of a common year) comes back as another day.
        if (Persian::jalali($noon) !== [$year, $month, $day]) {
            return null;
        }

        return $noon->setTime($hour, $minute)->format(LocalTime::MOMENT);
    }

    /** A moment the panel answered (ISO 8601) as a draft keeps it, on the shop's clock; null for none. */
    public static function fromIso(mixed $iso): ?string
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return LocalTime::of(Carbon::parse($iso))->format(LocalTime::MOMENT);
        } catch (\Throwable) {
            return null;
        }
    }

    /** A draft's moment in words: «۲۰ مهر ۱۴۰۵، ۱۸:۰۰». */
    public static function words(string $moment): string
    {
        $parsed = \DateTimeImmutable::createFromFormat(LocalTime::MOMENT, $moment, LocalTime::zone());

        return $parsed === false ? Persian::digits($moment) : Persian::date($parsed, true);
    }

    /** A draft's moment as it is typed back (its question's value now): «۱۴۰۵/۰۷/۲۰ ۱۸:۰۰». */
    public static function typed(string $moment): string
    {
        $parsed = \DateTimeImmutable::createFromFormat(LocalTime::MOMENT, $moment, LocalTime::zone());
        if ($parsed === false) {
            return Persian::digits($moment);
        }
        [$year, $month, $day] = Persian::jalali($parsed);

        return Persian::digits(sprintf('%04d/%02d/%02d %s', $year, $month, $day, $parsed->format('H:i')));
    }

    /** What a moment looks like typed, a week from now at 18: its question's example. */
    public static function example(): string
    {
        $then = (new \DateTimeImmutable('now', LocalTime::zone()))->modify('+7 days');
        [$year, $month, $day] = Persian::jalali($then);

        return Persian::digits(sprintf('%04d/%02d/%02d 18', $year, $month, $day));
    }

    /** `$days` from now on the shop's clock, at the next whole hour: a quick end of a window. */
    public static function inDays(int $days): string
    {
        $then = (new \DateTimeImmutable('now', LocalTime::zone()))->modify("+{$days} days");
        if ((int) $then->format('i') > 0 || (int) $then->format('s') > 0) {
            $then = $then->modify('+1 hour');
        }

        return $then->setTime((int) $then->format('H'), 0)->format(LocalTime::MOMENT);
    }

    /**
     * Jalali → Gregorian (the standard algorithm, the inverse of Persian's).
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function gregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
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
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $gm = 1;
        foreach ([31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31] as $length) {
            if ($gd <= $length) {
                break;
            }
            $gd -= $length;
            $gm++;
        }

        return [$gy, $gm, $gd];
    }
}
