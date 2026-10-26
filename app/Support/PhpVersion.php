<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The PHP releases the shop runs on: of each supported branch, the release that closed its latest known security holes
 * and every one after it. An older release of a listed branch has them open; a branch older than every listed one is
 * past PHP's support and gets no fix; a branch newer than every listed one came out with them closed.
 *
 * composer.json's `php` says the same (composer(); PhpVersionTest holds the two together). The installer does not
 * install on an older release (Requirements), the panel's update does not install a release on one (Updates\Updater,
 * from the release's own list in release.json), and the owner's panel says so over every page while the shop still
 * runs on one (Admin\Services\SystemStatus).
 */
final class PhpVersion
{
    /**
     * By branch, its first release without the holes: GHSA-w8hw-w9fx-j79w (CVE-2026-109618) and GHSA-6wmf-4rv5-p3rp
     * (CVE-2026-109619), remote code execution by anyone who can send the shop a request, both rated critical, closed on
     * 21 October 2026 — and every hole closed before them. PHP 8.2, though PHP still patches it until the end of 2026, is
     * no branch the shop runs on since 0.7.1. When PHP announces a security release, its versions go here.
     */
    public const MINIMUMS = ['8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];

    /** The PHP a test plays the host as (setTestHost()); null: the real one. */
    private static ?string $testHost = null;

    /** The host's PHP: PHP_VERSION — unless a test plays another (setTestHost()). */
    public static function host(): string
    {
        return self::$testHost ?? PHP_VERSION;
    }

    /** Play the host as PHP `$version` — for tests only, as Carbon::setTestNow() plays the clock; null: the real one again. */
    public static function setTestHost(?string $version): void
    {
        self::$testHost = $version;
    }

    /**
     * Whether `$version` ("8.3.36", "8.4.27-dev") is one the shop runs on: at least its branch's minimum in `$minimums`,
     * or of a branch newer than every listed one. None is listed (a release that names none): any is.
     *
     * @param array<string, string> $minimums By branch, as MINIMUMS
     */
    public static function isSafe(string $version, array $minimums = self::MINIMUMS): bool
    {
        if ($minimums === []) {
            return true;
        }
        $branch = self::branch($version);
        if (isset($minimums[$branch])) {
            return version_compare(self::release($version), $minimums[$branch], '>=');
        }
        $branches = self::branches($minimums);

        return version_compare($branch, (string) end($branches), '>');
    }

    /**
     * The release to name to the owner of a host on `$version`: its branch's minimum, else — a branch past its support
     * — the oldest branch's.
     *
     * @param array<string, string> $minimums By branch, as MINIMUMS
     */
    public static function required(string $version, array $minimums = self::MINIMUMS): string
    {
        return $minimums[self::branch($version)] ?? $minimums[self::branches($minimums)[0] ?? ''] ?? '';
    }

    /**
     * composer.json's `php`: each listed branch from its minimum, and every branch after the newest
     * ("~8.3.36 || … || >=8.5.12").
     */
    public static function composer(): string
    {
        $branches = self::branches(self::MINIMUMS);
        $newest = end($branches);
        $parts = array_map(static fn(string $branch): string => ($branch === $newest ? '>=' : '~') . self::MINIMUMS[$branch], $branches);

        return implode(' || ', $parts);
    }

    /** "8.3" of "8.3.36" (and of "8.3.36-dev"). */
    private static function branch(string $version): string
    {
        return implode('.', array_slice(explode('.', self::release($version)), 0, 2));
    }

    /** The version without what follows its numbers: "8.4.27" of "8.4.27-dev" or "8.4.27RC1". */
    private static function release(string $version): string
    {
        return preg_match('/^\d+(?:\.\d+){0,2}/', $version, $match) === 1 ? $match[0] : $version;
    }

    /**
     * @param array<string, string> $minimums
     * @return list<string> The branches `$minimums` lists, oldest first
     */
    private static function branches(array $minimums): array
    {
        $branches = array_map(strval(...), array_keys($minimums));
        usort($branches, version_compare(...));

        return $branches;
    }
}
