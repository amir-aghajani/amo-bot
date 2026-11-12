<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How the app reads an Iranian mobile number a customer types for a code by SMS: «09121234567», «9121234567»,
 * «+989121234567», «00989121234567» or «989121234567», in Latin, Persian or Arabic digits, spaces, dashes, dots or
 * brackets between the groups — kept in E.164 («+989121234567»), as `users.phone` keeps a number. A landline, another
 * country's number or anything else is none: the shop's SMS reaches Iran's mobiles.
 */
final class Mobile
{
    /** The longest a typed number is read: more is no phone number however it is spaced. */
    private const TYPED_MAX = 32;

    /** What people put between a number's groups: spaces of any kind, dashes, dots, brackets, direction marks. */
    private const SEPARATORS = '/[\s\-.()\x{200C}\x{200E}\x{200F}\x{202A}-\x{202E}]+/u';

    /** An Iranian mobile's national part: «9» and nine more digits. */
    private const NATIONAL = '/^9\d{9}$/';

    public const MISSING = 'شماره موبایل را وارد کنید.';

    public const NOT_A_MOBILE = 'شماره موبایل درست نیست؛ یک شماره موبایل ایران را مثل 09121234567 بنویسید.';

    /** The number in E.164; null when it is none (problem() says why). */
    public static function of(string $typed): ?string
    {
        if (mb_strlen($typed) > self::TYPED_MAX) {
            return null;
        }
        $digits = (string) preg_replace(self::SEPARATORS, '', Persian::latinDigits(trim($typed)));
        $national = match (true) {
            str_starts_with($digits, '+98') => substr($digits, 3),
            str_starts_with($digits, '0098') => substr($digits, 4),
            str_starts_with($digits, '98') && strlen($digits) === 12 => substr($digits, 2),
            default => $digits,
        };
        // The trunk «0» of «0912…», typed after the country code too («+98 0912…»).
        if (strlen($national) === 11 && str_starts_with($national, '0')) {
            $national = substr($national, 1);
        }

        return preg_match(self::NATIONAL, $national) === 1 ? '+98' . $national : null;
    }

    /** Why `$typed` is no Iranian mobile number; null when it is one. */
    public static function problem(string $typed): ?string
    {
        return match (true) {
            trim($typed) === '' => self::MISSING,
            self::of($typed) === null => self::NOT_A_MOBILE,
            default => null,
        };
    }

    /** A kept number as a log line may name it: its country and operator code and its last four digits («+98912***4567»). */
    public static function masked(string $e164): string
    {
        return strlen($e164) > 10 ? substr($e164, 0, 6) . '***' . substr($e164, -4) : '***';
    }
}
