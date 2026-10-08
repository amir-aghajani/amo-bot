<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one rule for a password, whoever chooses it — the panel's owner (Auth\Credentials) or a customer on the shop's
 * website: MIN_LENGTH characters at least, MAX_BYTES at most — what bcrypt reads of it: a longer one would open with its
 * start alone (a Persian letter is two bytes) —, and no NUL byte, where bcrypt stops reading. Kept as PHP's own hash
 * (password_hash(), bcrypt today), never as typed.
 */
final class Password
{
    public const MIN_LENGTH = 8;

    public const MAX_BYTES = 72;

    public const NUL = 'رمز عبور نمی‌تواند کاراکتر NUL داشته باشد.';

    /** Why `$password` cannot be one, in its owner's words; null when it can. */
    public static function problem(string $password): ?string
    {
        return match (true) {
            str_contains($password, "\0") => self::NUL,
            mb_strlen($password) < self::MIN_LENGTH => 'رمز عبور دست‌کم ' . self::MIN_LENGTH . ' کاراکتر است.',
            strlen($password) > self::MAX_BYTES => 'رمز عبور حداکثر ' . self::MAX_BYTES . ' بایت است: ' . self::MAX_BYTES . ' حرف انگلیسی، یا نصف آن حرف فارسی.',
            default => null,
        };
    }

    /** The hash kept for it. */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
