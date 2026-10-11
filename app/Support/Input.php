<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Exceptions\ValidationException;

/**
 * Reads loosely typed input (JSON, forms, query strings, the bot's typed text) one way everywhere: Persian and Arabic
 * digits count, a non-scalar reads as empty, a native number follows the rules of its text. Text that is not UTF-8 is
 * refused under its field — the database would refuse it with a 500.
 */
final class Input
{
    /** The longest note an admin writes on a decision (a payment's, an order's). */
    public const NOTE_MAX = 300;

    /** Text that is not UTF-8: no browser sends it, and the database takes nothing else. */
    public const NOT_UTF8 = 'متن این فیلد درست نیست؛ آن را با کدگذاری UTF-8 بفرستید.';

    /** The whole part of an amount: up to twelve digits, or thousands separated by commas or spaces («1,5» is no 15). */
    private const WHOLE = '[0-9]{1,3}(?:[, ][0-9]{3})+|[0-9]{1,12}';

    /** Booleans as browsers and JSON send them: true/1/"1"/"true"/"on"/"yes"; everything else is false. */
    public static function truthy(mixed $value): bool
    {
        return in_array(is_string($value) ? strtolower($value) : $value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** Whether the value reads as a boolean at all (true/false, 0/1, as values or text). */
    public static function isBoolean(mixed $value): bool
    {
        return is_bool($value) || in_array(is_string($value) ? strtolower($value) : $value, [0, 1, '0', '1', 'true', 'false'], true);
    }

    /**
     * A trimmed string; missing or non-scalar = "".
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `$field`: not UTF-8
     */
    public static function text(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_scalar($value) ? trim(self::utf8((string) $value, $field)) : '';
    }

    /**
     * A password as typed, not trimmed (a space at either end is part of it); "" for anything that is not text.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `$field`: not UTF-8
     */
    public static function password(array $input, string $field): string
    {
        $value = $input[$field] ?? '';

        return is_string($value) ? self::utf8($value, $field) : '';
    }

    /**
     * A secret the form never gets back (a password, a token): left blank or not sent it keeps `$stored`,
     * `clear_<field>` empties it, anything else replaces it, trimmed.
     *
     * @param array<string, mixed> $input
     */
    public static function secret(array $input, string $field, string $stored): string
    {
        if (self::truthy($input['clear_' . $field] ?? false)) {
            return '';
        }
        $typed = self::text($input, $field);

        return $typed !== '' ? $typed : $stored;
    }

    /**
     * A non-negative integer of up to nine digits; anything else = null.
     *
     * @param array<string, mixed> $input
     */
    public static function integer(array $input, string $field): ?int
    {
        return self::integerOf($input[$field] ?? null);
    }

    /** integer()'s rule for one value (the bot's typed text, a callback's number). */
    public static function integerOf(mixed $value): ?int
    {
        $text = self::digitsOf($value);

        return preg_match('/^\d{1,9}$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * A whole number an admin types into a form ("10000", "10,000", "۱۰٬۰۰۰"): thousands separators allowed, no unit, no
     * fraction; null otherwise.
     */
    public static function wholeOf(mixed $value): ?int
    {
        $text = self::numberOf($value);

        return preg_match('/^(?:' . self::WHOLE . ')$/', $text) === 1 ? self::integerOf(str_replace([',', ' '], '', $text)) : null;
    }

    /**
     * Split a typed list of numbers ("50,000, 100,000", "۵۰٬۰۰۰، ۱۰۰٬۰۰۰") on spaces, «،» and commas — except a comma
     * between groups of three digits, a thousands separator — each part for wholeOf() to read.
     *
     * @return list<string>
     */
    public static function numbersOf(string $typed): array
    {
        preg_match_all('/[0-9]{1,3}(?:,[0-9]{3})+(?![0-9])|[^\s,،]+/u', self::numberOf($typed), $matches);

        return $matches[0];
    }

    /**
     * A whole amount a customer types ("۱۵۰٬۰۰۰ تومان", "150 000", "۲۰ گیگ": a unit word allowed) or the API's own
     * "150000.00"; null for a fraction, two numbers, words alone or more than twelve digits.
     */
    public static function amountOf(mixed $value): ?int
    {
        if (preg_match('/^(' . self::WHOLE . ')(?:\.0{1,2})?\s*\p{L}*$/u', self::numberOf($value), $match) !== 1) {
            return null;
        }

        $digits = str_replace([',', ' '], '', $match[1]);

        return strlen($digits) <= 12 ? (int) $digits : null;
    }

    /**
     * Money an admin types (a price, a wallet adjustment): whole Toman, read as amountOf() reads it; null otherwise, so
     * the caller refuses the field with its own message.
     *
     * @param array<string, mixed> $input
     */
    public static function amount(array $input, string $field): ?int
    {
        return self::amountOf($input[$field] ?? null);
    }

    /**
     * A non-negative amount with up to two decimals, as a string (GB of traffic); thousands separators allowed; null
     * otherwise.
     *
     * @param array<string, mixed> $input
     */
    public static function decimal(array $input, string $field): ?string
    {
        return self::decimalOf($input[$field] ?? null);
    }

    /** decimal()'s rule for one value. */
    public static function decimalOf(mixed $value): ?string
    {
        if (preg_match('/^(' . self::WHOLE . ')(\.[0-9]{1,2})?$/', self::numberOf($value), $match) !== 1) {
            return null;
        }

        $whole = str_replace([',', ' '], '', $match[1]);

        return strlen($whole) <= 12 ? $whole . ($match[2] ?? '') : null;
    }

    /**
     * A free-text note: trimmed, null when blank, refused past `$max` characters.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public static function note(array $input, string $field, int $max = self::NOTE_MAX): ?string
    {
        $note = self::text($input, $field);
        if (mb_strlen($note) > $max) {
            throw new ValidationException([$field => [Validation::tooLong('توضیح', $max)]]);
        }

        return $note !== '' ? $note : null;
    }

    /**
     * The `is_active` switch of a create/edit form: what was sent, else the row's (`$default`), else on.
     *
     * @param array<string, mixed> $input
     */
    public static function active(array $input, ?bool $default): bool
    {
        return self::truthy($input['is_active'] ?? $default ?? true);
    }

    /** The value as Latin-digit text, whatever it was sent as; "" for anything that is not a scalar. */
    private static function digitsOf(mixed $value): string
    {
        return Persian::latinDigits(is_scalar($value) ? trim((string) $value) : '');
    }

    /**
     * An amount as Latin-digit text with its separators kept: «٬» becomes a comma (Persian::latinDigits() would drop it,
     * and «۱٬۵» is no 15).
     */
    private static function numberOf(mixed $value): string
    {
        return self::digitsOf(is_scalar($value) ? str_replace('٬', ',', (string) $value) : null);
    }

    /**
     * `$text` unchanged when it is UTF-8 (a form's field or a query's value need not be).
     *
     * @throws ValidationException 422 on `$field`
     */
    private static function utf8(string $text, string $field): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : throw ValidationException::on($field, self::NOT_UTF8);
    }
}
