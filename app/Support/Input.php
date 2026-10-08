<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Exceptions\ValidationException;

/**
 * Reading loosely typed request input (JSON bodies, forms, query strings, the bot's typed text) the
 * same way everywhere: Persian/Arabic digits are accepted for numbers, anything that is not a scalar
 * reads as empty, and a native number goes through the same rules as its text form. Text is UTF-8 whatever
 * the body: a JSON body cannot be anything else (one that is not reads as none), and a form's field or a
 * query's value that is not is refused under its name — never handed on to the database, which would refuse
 * it with a 500.
 */
final class Input
{
    /** The longest note an admin writes on a payment or an order. */
    public const NOTE_MAX = 300;

    /** Text that is not UTF-8: no browser sends it, and the database takes nothing else. */
    public const NOT_UTF8 = 'متن این فیلد درست نیست؛ آن را با کدگذاری UTF-8 بفرستید.';

    /**
     * The whole part of an amount: up to twelve digits, or digits set apart in thousands by commas or spaces — a separator
     * between groups of three, never anywhere else («1,5» is no 15).
     */
    private const WHOLE = '[0-9]{1,3}(?:[, ][0-9]{3})+|[0-9]{1,12}';

    /** Booleans as browsers and JSON send them: true/1/"1"/"true"/"on"/"yes"; everything else is false. */
    public static function truthy(mixed $value): bool
    {
        return in_array(is_string($value) ? strtolower($value) : $value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** Whether the value reads as a boolean at all (true/false, 0/1 as numbers or text) — a switch that was not sent, or sent as garbage, is neither. */
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
     * A password as typed: a space at either end is part of it, so nothing is trimmed; anything that is not text is none
     * ("").
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

    /** The same rule for one value (the bot's typed text, a callback's number). */
    public static function integerOf(mixed $value): ?int
    {
        $text = self::digitsOf($value);

        return preg_match('/^\d{1,9}$/', $text) === 1 ? (int) $text : null;
    }

    /**
     * A whole number an admin types into a form — "10000", "10,000", "۱۰٬۰۰۰" —: integerOf()'s, its thousands set apart
     * as amountOf() reads them (only between groups of three), and nothing else around it — no unit, no fraction —; null
     * otherwise.
     */
    public static function wholeOf(mixed $value): ?int
    {
        $text = self::numberOf($value);

        return preg_match('/^(?:' . self::WHOLE . ')$/', $text) === 1 ? self::integerOf(str_replace([',', ' '], '', $text)) : null;
    }

    /**
     * The numbers of a list an admin types — "50000, 100000", "50,000 100,000", "۵۰٬۰۰۰، ۱۰۰٬۰۰۰" —, each as typed, for
     * wholeOf() to read: apart by spaces, «،» or a comma — but a comma between groups of three digits, which sets a
     * number's thousands apart («50,000, 100,000» is two numbers; «1,5» is two as well, never 15).
     *
     * @return list<string>
     */
    public static function numbersOf(string $typed): array
    {
        preg_match_all('/[0-9]{1,3}(?:,[0-9]{3})+(?![0-9])|[^\s,،]+/u', self::numberOf($typed), $matches);

        return $matches[0];
    }

    /**
     * A whole amount a customer typed in the bot or on the website — "۱۵۰٬۰۰۰ تومان", "150,000", "150 000", "۲۰ گیگ":
     * digits in any script, thousands set apart the usual ways, a unit word after it —, or the API's own decimal form of
     * one, which the Store API answers amounts in ("150000.00": a fraction of zero); anything else (a fraction, two
     * numbers, words alone, past twelve digits) = null.
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
     * Money the admin types — a plan's price, a level's price per GB, a wallet set right by hand —: whole Toman, the
     * shop's only unit, as amountOf() reads it ("120000", "۱۲۰٬۰۰۰ تومان", the API's own "120000.00"); a fraction of a
     * Toman, or anything else, = null — for the field to refuse in its words.
     *
     * @param array<string, mixed> $input
     */
    public static function amount(array $input, string $field): ?int
    {
        return self::amountOf($input[$field] ?? null);
    }

    /**
     * A non-negative amount with up to two decimals, as a string — a quantity that may have a fraction (a GB of
     * traffic); thousands set apart as amountOf() takes them; anything else = null.
     *
     * @param array<string, mixed> $input
     */
    public static function decimal(array $input, string $field): ?string
    {
        return self::decimalOf($input[$field] ?? null);
    }

    /** The same rule for one value. */
    public static function decimalOf(mixed $value): ?string
    {
        if (preg_match('/^(' . self::WHOLE . ')(\.[0-9]{1,2})?$/', self::numberOf($value), $match) !== 1) {
            return null;
        }

        $whole = str_replace([',', ' '], '', $match[1]);

        return strlen($whole) <= 12 ? $whole . ($match[2] ?? '') : null;
    }

    /**
     * A free-text note: trimmed, null when blank, refused past `$max` with the one wording every
     * note field shares.
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
     * The `is_active` switch of a create/edit form: what was sent, else what the row has
     * (`$default`), else on — a new row starts active.
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
     * An amount as Latin-digit text, its thousands set apart as typed: the Persian separator «٬» kept, as the comma it is
     * (Persian::latinDigits() drops it — «۱٬۵» is no 15 either).
     */
    private static function numberOf(mixed $value): string
    {
        return self::digitsOf(is_scalar($value) ? str_replace('٬', ',', (string) $value) : null);
    }

    /**
     * `$text` as it came, while it is UTF-8 — what a form's field or a query's value need not be.
     *
     * @throws ValidationException 422 on `$field`
     */
    private static function utf8(string $text, string $field): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : throw ValidationException::on($field, self::NOT_UTF8);
    }
}
