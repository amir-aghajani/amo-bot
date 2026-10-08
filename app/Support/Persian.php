<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Persian presentation helpers for everything the PHP side words for people (bot messages, API
 * error texts): Persian digits and Jalali dates. The React admin has its own copy in lib/format.ts.
 */
final class Persian
{
    private const DIGITS = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

    private const JALALI_MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

    /** Replace ASCII digits with Persian ones. */
    public static function digits(string|int|float $value): string
    {
        return strtr((string) $value, self::DIGITS);
    }

    /** The reverse for user input: Persian and Arabic-Indic digits (and the Persian decimal mark) become ASCII. */
    public static function latinDigits(string $value): string
    {
        return strtr($value, array_flip(self::DIGITS) + ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.', '٬' => '']);
    }

    /** "۳۰ دقیقه", "۲ ساعت", "۱ ساعت و ۳۰ دقیقه", "۱ روز" — a duration given in minutes. */
    public static function minutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            return self::digits(intdiv($minutes, 1440)) . ' روز';
        }

        $parts = [];
        if ($minutes >= 60) {
            $parts[] = self::digits(intdiv($minutes, 60)) . ' ساعت';
        }
        if ($minutes % 60 !== 0 || $parts === []) {
            $parts[] = self::digits($minutes % 60) . ' دقیقه';
        }

        return implode(' و ', $parts);
    }

    /** Thousands-separated integer with Persian digits: "۱۲٬۳۴۵". */
    public static function number(int|float $value): string
    {
        return self::digits(number_format($value, 0, '٫', '٬'));
    }

    /** "۲۶ شهریور ۱۴۰۵" — Jalali date in the shop's zone (LocalTime), computed here so no PHP extension is required. */
    public static function date(\DateTimeInterface $date, bool $withTime = false): string
    {
        $date = LocalTime::of($date);
        [$year, $month, $day] = self::toJalali((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));

        $text = sprintf('%s %s %s', self::digits($day), self::JALALI_MONTHS[$month - 1], self::digits($year));

        return $withTime ? $text . '، ' . self::digits($date->format('H:i')) : $text;
    }

    /**
     * Gregorian → Jalali (the standard algorithm used by most Persian calendar libraries).
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gDaysInMonth[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}
