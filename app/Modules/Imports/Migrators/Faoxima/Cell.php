<?php

declare(strict_types=1);

namespace App\Modules\Imports\Migrators\Faoxima;

use App\Support\Money;
use App\Support\Persian;

/**
 * A value of Faoxima's database as it writes it, read: text whose placeholders («none», «null») mean nothing, numbers
 * kept as text (Persian digits or thousands separators now and then), unix times, comma-separated lists.
 */
final class Cell
{
    /** What Faoxima writes in a text column that holds nothing (it never leaves one empty when it can say so). */
    private const PLACEHOLDERS = ['none', 'null'];

    /** Trimmed text; null for none, blank or a placeholder. */
    public static function text(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' || in_array(strtolower($text), self::PLACEHOLDERS, true) ? null : $text;
    }

    /**
     * A number in its plainest digits ("10", "12.5", "-3"): Persian digits and thousands separators read, trailing zeros
     * dropped; null for anything that is no number.
     *
     * @return numeric-string|null
     */
    public static function number(?string $value): ?string
    {
        $text = str_replace([',', ' '], '', Persian::latinDigits(trim((string) $value)));
        if (preg_match('/^-?\d{1,15}(\.\d+)?$/', $text) !== 1) {
            return null;
        }
        $plain = bcadd($text, '0', 6);
        $plain = str_contains($plain, '.') ? rtrim(rtrim($plain, '0'), '.') : $plain;

        return $plain === '-0' ? '0' : $plain;
    }

    /** A whole number; 0 for none (a fraction is cut). */
    public static function whole(?string $value): int
    {
        $number = self::number($value);

        return $number === null ? 0 : (int) $number;
    }

    /** Toman as the shop keeps it ("120000.00"); 0 for none. */
    public static function amount(?string $value): string
    {
        return Money::normalize(self::number($value) ?? '0');
    }

    /** A moment Faoxima kept as unix seconds; null for none, 0 or anything else. */
    public static function moment(?string $value): ?\DateTimeImmutable
    {
        $seconds = trim((string) $value);

        return preg_match('/^[1-9]\d{0,10}$/', $seconds) === 1 ? new \DateTimeImmutable('@' . $seconds) : null;
    }

    /**
     * A comma-separated list, each item trimmed, blanks and repeats left out.
     *
     * @return list<string>
     */
    public static function list(?string $value): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', (string) $value)), static fn(string $item): bool => $item !== '')));
    }
}
