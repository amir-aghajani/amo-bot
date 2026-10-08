<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Modules\Updates\Exceptions\UpdateRefusedException;

/**
 * What a release is, as its builder signed it — release.json, written by scripts/release.php and signed by the release
 * workflow: its version, its zip (the file's name, its size and sha256), the PHP and the extensions it needs, and the
 * top-level paths of the app it replaces. Nothing in it is believed before the release key's signature over its very
 * bytes verifies (verified()), and it must be the release asked for: a release signed for one version offered as another
 * is no update.
 */
final class Manifest
{
    /** The largest zip a release is taken as: one is a few tens of MB. */
    public const MAX_BYTES = 150 * 1024 * 1024;

    public const UNSIGNED = 'امضای فایل‌های این نسخه با کلید AmoBot جور نیست؛ این فایل‌ها نصب نمی‌شوند.';

    public const NOT_THIS_RELEASE = 'فایل مشخصات این نسخه (release.json) با خود نسخه جور نیست؛ نصب نمی‌شود.';

    /** What every release replaces — and every version of the app has: the app, its boot, its front door, its packages. */
    public const ESSENTIAL = ['app', 'bootstrap', 'public', 'vendor'];

    /** What no release replaces: the shop's configuration and its files. */
    private const KEPT = ['config.php', 'storage'];

    /** A top-level path of the app: a plain name, the root's .htaccess the one hidden one. */
    private const PATH = '/^(?:\.htaccess|[A-Za-z0-9][A-Za-z0-9._-]*)$/';

    /**
     * @param list<string> $extensions
     * @param list<string> $paths
     */
    private function __construct(
        public readonly string $version,
        /** The zip's name: amobot-<version>.zip. */
        public readonly string $file,
        public readonly int $size,
        public readonly string $sha256,
        /** The oldest PHP it runs on ("8.2"). */
        public readonly string $php,
        /** The PHP extensions it needs (composer.json's ext-*), by name. */
        public readonly array $extensions,
        /** The app's top-level folders and files it replaces — never config.php nor storage/. */
        public readonly array $paths,
    ) {}

    /**
     * The manifest of release `$version` — once `$key` has signed its bytes, and it says it is that release's.
     *
     * @throws UpdateRefusedException when it is not signed by the key, or not that release's manifest
     */
    public static function verified(string $json, string $signature, ReleaseKey $key, string $version): self
    {
        if (!$key->signed($json, $signature)) {
            throw UpdateRefusedException::refused(self::UNSIGNED);
        }

        $data = json_decode($json, true);
        $data = is_array($data) ? $data : [];
        $text = static fn(string $field): string => is_string($data[$field] ?? null) ? $data[$field] : '';
        $size = is_int($data['size'] ?? null) ? $data['size'] : 0;
        $extensions = self::names($data['extensions'] ?? null, '/^[a-z0-9_]+$/');
        $paths = self::names($data['paths'] ?? null, self::PATH);

        $isThisRelease = $text('version') === $version
            && $text('file') === "amobot-{$version}.zip"
            && $size > 0 && $size <= self::MAX_BYTES
            && preg_match('/^[0-9a-f]{64}$/', $text('sha256')) === 1
            && preg_match('/^\d+\.\d+(\.\d+)?$/', $text('php')) === 1
            && $extensions !== null
            && $paths !== null && count(array_unique($paths)) === count($paths)
            && array_diff(self::ESSENTIAL, $paths) === [] && array_intersect(self::KEPT, $paths) === [];
        if (!$isThisRelease) {
            throw UpdateRefusedException::refused(self::NOT_THIS_RELEASE);
        }

        return new self($version, $text('file'), $size, $text('sha256'), $text('php'), $extensions, $paths);
    }

    /** @return list<string>|null The value when it is a list of names each `$pattern` takes; null otherwise */
    private static function names(mixed $value, string $pattern): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        foreach ($value as $name) {
            if (!is_string($name) || preg_match($pattern, $name) !== 1) {
                return null;
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}
