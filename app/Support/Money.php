<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money is stored as decimal(14,2) strings and handled with bcmath; this is the one place that
 * normalises and formats amounts, so the bot, the API and the ledger never disagree on rounding.
 *
 * The shop trades in one currency, Toman: amounts are whole and are always shown as "۱۲۰٬۰۰۰ تومان".
 */
final class Money
{
    public const SCALE = 2;

    /** The unit as customers read it. */
    public const UNIT = 'تومان';

    /**
     * "12.5" → "12.50": the canonical string form for storage and bcmath. Text may carry Persian
     * digits; a third decimal rounds half up. Floats and ints are cast, nothing else is: an amount that
     * is not a number is a programming error, not something to guess at.
     *
     * @return numeric-string
     */
    public static function normalize(string|float|int $amount): string
    {
        $text = match (true) {
            is_string($amount) => Persian::latinDigits(trim($amount)),
            is_float($amount) => number_format($amount, self::SCALE, '.', ''),
            default => (string) $amount,
        };

        return bcadd($text, str_starts_with($text, '-') ? '-0.005' : '0.005', self::SCALE);
    }

    /** @return numeric-string */
    public static function add(string|float|int $a, string|float|int $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** @return numeric-string */
    public static function subtract(string|float|int $a, string|float|int $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    /** @return numeric-string `$times` of the amount — several of one price. */
    public static function times(string|float|int $amount, int $times): string
    {
        return bcmul(self::normalize($amount), (string) $times, self::SCALE);
    }

    /** -1, 0 or 1 like bccomp(). */
    public static function compare(string|float|int $a, string|float|int $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function isPositive(string|float|int $amount): bool
    {
        return self::compare($amount, 0) > 0;
    }

    /**
     * `$percent` percent of an amount, in whole Toman rounded down — the shop never pays out a fraction.
     *
     * @return numeric-string
     */
    public static function percentOf(string|float|int $amount, int $percent): string
    {
        return self::normalize(bcdiv(bcmul(self::normalize($amount), (string) $percent, self::SCALE), '100', 0));
    }

    /** Persian display form: "۱۲۰٬۰۰۰ تومان". Toman has no fraction, so none is shown. */
    public static function format(string|float|int $amount): string
    {
        return Persian::number((float) self::normalize($amount)) . ' ' . self::UNIT;
    }
}
