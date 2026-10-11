<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

/**
 * A device's name for a customer's session list, read from its User-Agent: «Chrome در Windows», «مرورگر در Android» for an
 * unknown browser, «دستگاه ناشناس» for nothing known. Only words for people to recognise their devices — never a check.
 */
final class DeviceName
{
    /** Browsers by a mark in their User-Agent; those built on another come first, since they name it too (Edge says Chrome). */
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

    /** The device's name, MAX characters at most. */
    public static function of(string $userAgent): string
    {
        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        $name = $system === null ? ($browser ?? 'دستگاه ناشناس') : ($browser ?? 'مرورگر') . ' در ' . $system;

        return mb_substr($name, 0, self::MAX);
    }

    /** @param array<string, string> $names Mark => name, in the order they are tried */
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
