<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one password rule (the panel's owner and website customers alike): at least MIN_LENGTH characters, at most
 * MAX_BYTES — bcrypt reads no further, so a longer one would open with its start alone (a Persian letter is two
 * bytes) — and no NUL byte, where bcrypt stops reading.
 */
final class Password
{
    public const MIN_LENGTH = 8;

    public const MAX_BYTES = 72;

    public const NUL = 'رمز عبور نمی‌تواند کاراکتر NUL داشته باشد.';

    /** Why `$password` is refused; null when it is fine. */
    public static function problem(string $password): ?string
    {
        return match (true) {
            str_contains($password, "\0") => self::NUL,
            mb_strlen($password) < self::MIN_LENGTH => 'رمز عبور دست‌کم ' . self::MIN_LENGTH . ' کاراکتر است.',
            strlen($password) > self::MAX_BYTES => 'رمز عبور حداکثر ' . self::MAX_BYTES . ' بایت است: ' . self::MAX_BYTES . ' حرف انگلیسی، یا نصف آن حرف فارسی.',
            default => null,
        };
    }

    /** PHP's own hash of it (PASSWORD_DEFAULT). */
    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
