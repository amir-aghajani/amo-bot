<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How the app reads an email address: trimmed, lower case, at most MAX characters, valid by PHP's filter — and nothing
 * but an address. PHP's filter takes `"<ali@evil.example>"@example.com`, which a mail library delivers to the address
 * in brackets, so quotes, angle brackets, commas, semicolons and spaces are refused.
 */
final class Email
{
    /** The longest address kept: an indexed column's (191 characters of utf8mb4). */
    public const MAX = 191;

    /** Characters no kept address holds: quotes, angle brackets, commas, semicolons, any space. */
    private const NOT_AN_ADDRESS = '/["<>,;\s]/u';

    /** The address as kept; null when it is none (problem() says why). */
    public static function of(string $typed): ?string
    {
        return self::problem($typed) === null ? self::normalize($typed) : null;
    }

    /** Why `$typed` is no address, worded about `$label` («ایمیل»); null when it is one. */
    public static function problem(string $typed, string $label = 'ایمیل'): ?string
    {
        $email = self::normalize($typed);

        return match (true) {
            $email === '' => "{$label} را وارد کنید.",
            mb_strlen($email) > self::MAX => Validation::tooLong($label, self::MAX),
            preg_match(self::NOT_AN_ADDRESS, $email) === 1, filter_var($email, FILTER_VALIDATE_EMAIL) === false => "{$label} درست نیست؛ آن را مثل name@example.com بنویسید.",
            default => null,
        };
    }

    private static function normalize(string $typed): string
    {
        return mb_strtolower(trim($typed));
    }
}
