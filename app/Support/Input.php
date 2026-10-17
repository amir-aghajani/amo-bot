<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Exceptions\ValidationException;

/**
 * Reads loosely typed input (JSON, forms, query strings, the bot's typed text) one way everywhere: Persian and Arabic
 * digits count, a non-scalar reads as empty (and as a number, a boolean too), a native number follows the rules of its
 * text. Text that is not UTF-8 is refused under its field — the database would refuse it with a 500.
 */
final class Input
{
    /** The longest note an admin writes on a decision (a payment's, an order's). */
    public const NOTE_MAX = 300;

    /** Text that is not UTF-8: no browser sends it, and the database takes nothing else. */
    public const NOT_UTF8 = 'متن این فیلد درست نیست؛ آن را با کدگذاری UTF-8 بفرستید.';

    /** The whole part of an amount: up to twelve digits, or thousands separated by commas or spaces («1,5» is no 15). */
    private const WHOLE = '[0-9]{1,3}(?:[, ][0-9]{3})+|[0-9]{1,12}';

    /** The most an amount reads as: twelve digits. */
    private const AMOUNT_MOST = 999_999_999_999;

    /** A Telegram id: a positive number of at most 16 digits (Telegram's ids fit in 52 bits). */
    private const TELEGRAM_ID = '/^[1-9][0-9]{0,15}$/';

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
     * A Telegram id a person typed or sent — a number, or its digits (Persian ones too) with spaces around; null for
     * anything else (a handle, a sign, a leading zero, a boolean).
     */
    public static function telegramIdOf(mixed $value): ?int
    {
        $digits = is_int($value) || is_string($value) ? self::digitsOf($value) : '';

        return preg_match(self::TELEGRAM_ID, $digits) === 1 ? (int) $digits : null;
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
        // Text that is not UTF-8 matches nothing: it is one part no number reads, never an empty list.
        return preg_match_all('/[0-9]{1,3}(?:,[0-9]{3})+(?![0-9])|[^\s,،]+/u', self::numberOf($typed), $matches) !== false ? $matches[0] : [$typed];
    }

    /**
     * A whole amount of `$unit` a customer types ("۱۵۰٬۰۰۰ تومان", "150 000", "۲۰ گیگ", "۵۰ هزار تومان": the unit's own
     * words after it allowed, and its scale words before them, Unit::scales()) or the API's own "150000.00"; null for a
     * fraction, two numbers, words alone, a word that is not `$unit`'s («ریال», «ساعت», «روز» where GB are read: never a
     * number read in the wrong unit) or more than twelve digits.
     */
    public static function amountOf(mixed $value, Unit $unit): ?int
    {
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], self::numberOf($value));
        $scales = $unit->scales();
        $scaled = $scales === [] ? '' : '(' . implode('|', array_keys($scales)) . ')?[\s\x{200C}]*';
        if (preg_match('/^(' . self::WHOLE . ')(?:\.0{1,2})?[\s\x{200C}]*' . $scaled . '(?:' . $unit->words() . ')?$/iu', $text, $match) !== 1) {
            return null;
        }

        $digits = str_replace([',', ' '], '', $match[1]);
        $scale = $scales[$match[2] ?? ''] ?? 1;

        return strlen($digits) <= 12 && (int) $digits <= intdiv(self::AMOUNT_MOST, $scale) ? (int) $digits * $scale : null;
    }

    /**
     * Money an admin types (a price, a wallet adjustment): whole Toman, read as amountOf() reads it; null otherwise, so
     * the caller refuses the field with its own message.
     *
     * @param array<string, mixed> $input
     */
    public static function amount(array $input, string $field): ?int
    {
        return self::amountOf($input[$field] ?? null, Unit::Toman);
    }

    /**
     * Money an admin may leave blank (a bound, a cap, a price of its own): null or blank text is none (null); anything
     * else is whole Toman from `$least` to `$most`, read as amountOf() reads it and kept as Money keeps it — false when it
     * is not, so the caller refuses the field with its own message.
     *
     * @return numeric-string|false|null
     */
    public static function optionalAmountOf(mixed $value, int $least, int $most): string|false|null
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $amount = self::amountOf($value, Unit::Toman);

        return $amount !== null && $amount >= $least && $amount <= $most ? Money::normalize($amount) : false;
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

    /**
     * The value as Latin-digit text, whatever it was sent as; "" for anything that is not a scalar, and for a boolean — a
     * JSON `true` is no 1 (a plan's, a service's id).
     */
    private static function digitsOf(mixed $value): string
    {
        return Persian::latinDigits(is_scalar($value) && !is_bool($value) ? trim((string) $value) : '');
    }

    /**
     * An amount as Latin-digit text with its separators kept: «٬» becomes a comma (Persian::latinDigits() would drop it,
     * and «۱٬۵» is no 15), and any space — a pasted no-break or thin space too — an ASCII one.
     */
    private static function numberOf(mixed $value): string
    {
        return self::digitsOf(is_string($value) ? preg_replace('/\h/u', ' ', str_replace('٬', ',', $value)) ?? $value : $value);
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
