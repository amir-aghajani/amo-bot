<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

/**
 * A device as a customer's session list names it, from what its browser says it is (the User-Agent): «Chrome در
 * Windows», «Safari در iOS»; «مرورگر در Android» for a browser it does not know, «دستگاه ناشناس» for nothing it knows.
 * Words for a person to recognise their own devices by — never a check of anything.
 */
final class DeviceName
{
    /** Browsers by what their User-Agent carries — the ones built on another first, since they name it too (Edge says Chrome). */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'EdgA/' => 'Edge',
        'EdgiOS/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'YaBrowser/' => 'Yandex',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Version/' => 'Safari',
    ];

    /** Systems the same way — Android before Linux, iOS before macOS (an iPhone's says "like Mac OS X"). */
    private const SYSTEMS = [
        'Windows' => 'Windows',
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'iPod' => 'iOS',
        'CrOS' => 'ChromeOS',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    private const MAX = 128;

    public static function of(string $userAgent): string
    {
        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        $name = $system === null ? ($browser ?? 'دستگاه ناشناس') : ($browser ?? 'مرورگر') . ' در ' . $system;

        return mb_substr($name, 0, self::MAX);
    }

    /** @param array<string, string> $names What each mark in the User-Agent stands for, in the order they are told apart */
    private static function first(array $names, string $userAgent): ?string
    {
        foreach ($names as $mark => $name) {
            if (str_contains($userAgent, $mark)) {
                return $name;
            }
        }

        return null;
    }
}
