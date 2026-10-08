<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one rule for an email address, wherever one is typed — a customer's on the shop's website, the address the shop's
 * emails come from: trimmed and in lower case (one address, however it is typed), at most MAX characters, an address a
 * mail can go to (PHP's own check: a name, an "@", a domain with a dot in it) — and nothing but the address: no quoted
 * name, and none of the characters a mail library reads as more than one (NOT_AN_ADDRESS). PHP's check takes a quoted
 * name like `"<ali@evil.example>"@example.com`, which a mail library delivers to the address in the brackets: the
 * address kept, shown and counted would not be the mailbox its codes reach.
 */
final class Email
{
    /** The longest address kept: an indexed column's (191 characters of utf8mb4). */
    public const MAX = 191;

    /** What no address the shop keeps holds: a quote (a quoted name), an angle bracket, a comma, a semicolon, a space of any kind. */
    private const NOT_AN_ADDRESS = '/["<>,;\s]/u';

    /** The address as the shop keeps it; null when it is none (problem() says why). */
    public static function of(string $typed): ?string
    {
        return self::problem($typed) === null ? self::normalize($typed) : null;
    }

    /** Why `$typed` is no address, in its owner's words — about `$label` («ایمیل», «ایمیل فرستنده») —; null when it is one. */
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
