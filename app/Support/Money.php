<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Money: decimal(14,2) strings handled with bcmath, normalised and formatted here alone so nothing disagrees on
 * rounding. The one currency is Toman, in whole amounts, shown as "۱۲۰٬۰۰۰ تومان".
 */
final class Money
{
    public const SCALE = 2;

    /** The unit as customers read it. */
    public const UNIT = 'تومان';

    /**
     * The most Toman a form takes where nothing of its own bounds the amount — a method's bounds and a card's daily cap,
     * an agent's credit and monthly minimum, a discount code's amounts, a wallet adjustment —: a billion, well inside
     * decimal(14,2). Each form words its own refusal; a price has a bound of its own (Catalog's Package::PRICE_MAX).
     */
    public const AMOUNT_MAX = 1_000_000_000;

    /**
     * "12.5" → "12.50", the form for storage and bcmath. Persian digits allowed; a third decimal rounds half up. Text
     * that is no number is a programming error, not something to guess at.
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
     * The amount, or zero when it is below — what is left of a sum, never a debt.
     *
     * @return numeric-string
     */
    public static function atLeastZero(string|float|int $amount): string
    {
        return self::compare($amount, 0) < 0 ? self::normalize(0) : self::normalize($amount);
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

    /**
     * `$part` of `$whole` of an amount — a price set for a period, charged for part of it —, in whole Toman rounded up.
     *
     * @return numeric-string
     */
    public static function shareOf(string|float|int $amount, int $part, int $whole): string
    {
        $scaled = bcmul(self::normalize($amount), (string) $part, self::SCALE);
        $share = bcdiv($scaled, (string) $whole, 0);

        return self::normalize(bccomp(bcmul($share, (string) $whole, self::SCALE), $scaled, self::SCALE) < 0 ? bcadd($share, '1', 0) : $share);
    }

    /**
     * The amount as a banking app takes it typed: whole Toman in Latin digits, no separators — "120000" —, rounded as
     * format() rounds it, so the digits copied are the amount the customer reads.
     */
    public static function digits(string|float|int $amount): string
    {
        $amount = self::normalize($amount);

        return bcadd($amount, str_starts_with($amount, '-') ? '-0.5' : '0.5', 0);
    }

    /** Persian display form: "۱۲۰٬۰۰۰ تومان". Toman has no fraction, so none is shown. */
    public static function format(string|float|int $amount): string
    {
        return Persian::number((float) self::normalize($amount)) . ' ' . self::UNIT;
    }
}
