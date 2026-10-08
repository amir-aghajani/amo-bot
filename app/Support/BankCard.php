<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Iranian bank card numbers: 16 digits with a Luhn check digit. Input may carry Persian/Arabic digits,
 * spaces or dashes; storage is the bare digits.
 */
final class BankCard
{
    /** Bare digits: "۶۰۳۷-۹۹۷۷ ۰۰۰۰ ۱۱۱۹" → "6037997700001119". */
    public static function normalize(string $value): string
    {
        return preg_replace('/\D+/', '', Persian::latinDigits($value)) ?? '';
    }

    /** 16 digits that pass the Luhn check (what every Iranian bank issues). */
    public static function isValid(string $digits): bool
    {
        if (preg_match('/^\d{16}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $char) {
            $n = (int) $char;
            if ($i % 2 === 1) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
        }

        return $sum % 10 === 0;
    }

    /** First and last four for lists and logs: "6037 •••• •••• 1119". */
    public static function mask(string $digits): string
    {
        if (strlen($digits) < 8) {
            return $digits;
        }

        return substr($digits, 0, 4) . ' •••• •••• ' . substr($digits, -4);
    }
}
