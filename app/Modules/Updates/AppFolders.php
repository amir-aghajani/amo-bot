<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;

/**
 * Swaps the app's top-level paths (app/, vendor/, public/, the root .htaccess…) for a release's, and back. A folder is
 * renamed (instant on one file system) aside into previous/; a file is copied aside and replaced in one rename, so it is
 * never missing. Both directions read each path's state off the disk, so a crash at any point is finished or taken back
 * by whichever code runs next. cPanel's own blocks in the .htaccess files (the PHP version) are carried into the
 * release's — without them the shop could wake up on another PHP.
 */
final class AppFolders
{
    /** The .htaccess files host tools write into, by the release path that brings each. */
    private const HOST_FILES = ['.htaccess' => '.htaccess', 'public' => 'public/.htaccess'];

    /** A host tool's block: from «# … BEGIN cPanel-generated …» to «# … END cPanel-generated …». */
    private const HOST_BLOCK = '/^#[^\r\n]*BEGIN cPanel-generated[^\r\n]*\R.*?^#[^\r\n]*END cPanel-generated[^\r\n]*/ms';

    public function __construct(
        /** The app's folder (Application::basePath()). */
        private readonly string $app,
        /** Where the app's paths a release replaced are kept (Workspace::previous()). */
        private readonly string $previous,
    ) {}

    /**
     * Put the release's paths in the app's place (those not swapped yet), the app's kept aside, the host's .htaccess
     * blocks carried over.
     *
     * @param list<string> $paths
     * @throws \RuntimeException when one cannot be moved: what moved stays so, for restore() to take back
     */
    public function install(string $release, array $paths): void
    {
        foreach ($paths as $path) {
            $new = "{$release}/{$path}";
            if (!self::exists($new)) {
                continue;
            }
            $current = "{$this->app}/{$path}";
            $aside = "{$this->previous}/{$path}";
            Disk::folder($this->previous);
            if (is_dir($new)) {
                if (self::exists($current)) {
                    if (self::exists($aside)) {
                        throw new \RuntimeException("{$aside} is there already: the app's {$path} has nowhere to go.");
                    }
                    Disk::move($current, $aside);
                }
            } elseif (is_file($current) && !self::exists($aside)) {
                Disk::copy($current, $aside);
            }
            Disk::move($new, $current);
        }

        foreach (self::HOST_FILES as $path => $file) {
            if (in_array($path, $paths, true)) {
                $this->keepHostLines($file);
            }
        }
    }

    /**
     * Undo install() as far as it went: the app's paths back, the release's back in its folder.
     *
     * @param list<string> $paths
     * @throws \RuntimeException when one cannot be moved back
     */
    public function restore(string $release, array $paths): void
    {
        foreach (array_reverse($paths) as $path) {
            $new = "{$release}/{$path}";
            $current = "{$this->app}/{$path}";
            $aside = "{$this->previous}/{$path}";
            // The release's copy is in the app's place once it left its folder.
            $swapped = !self::exists($new) && self::exists($current);
            Disk::folder(dirname($new));
            if ($swapped && is_dir($current)) {
                Disk::move($current, $new);
            } elseif ($swapped) {
                Disk::copy($current, $new);
                if (!is_file($aside)) {
                    Disk::delete($current, INF);
                }
            }
            if (self::exists($aside) && (!self::exists($current) || is_file($aside))) {
                Disk::move($aside, $current);
            }
        }
    }

    /**
     * The first of the app's paths PHP may not change ('.' for the app's folder: not its own, or not writable); null
     * when it may change them all.
     *
     * @param list<string> $paths
     */
    public function unchangeable(array $paths): ?string
    {
        if (!Files::processOwns($this->app) || !is_writable($this->app)) {
            return '.';
        }
        foreach ($paths as $path) {
            if (self::exists("{$this->app}/{$path}") && !is_writable("{$this->app}/{$path}")) {
                return $path;
            }
        }

        return null;
    }

    /** Whether an empty folder can be renamed from previous/ into the app's folder and back, as install() will do. */
    public function renames(): bool
    {
        $probe = '.amobot-update-' . bin2hex(random_bytes(4));
        try {
            Disk::folder("{$this->previous}/{$probe}");
            Disk::move("{$this->previous}/{$probe}", "{$this->app}/{$probe}");
            Disk::move("{$this->app}/{$probe}", "{$this->previous}/{$probe}");

            return true;
        } catch (\RuntimeException) {
            return false;
        } finally {
            foreach (["{$this->previous}/{$probe}", "{$this->app}/{$probe}"] as $left) {
                try {
                    Disk::delete($left, INF);
                } catch (\RuntimeException) {
                    // A probe the file system will not let go of stays: an empty folder, and the answer is the same.
                }
            }
        }
    }

    /** Whether the app runs from a clone of its repository: git updates it, not the updater. */
    public function isCheckout(): bool
    {
        return is_dir("{$this->app}/.git");
    }

    /** Whether the replaced version is kept aside in full, so a rollback is possible. */
    public function kept(): bool
    {
        foreach (Manifest::ESSENTIAL as $path) {
            if (!is_dir("{$this->previous}/{$path}")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Append to the release's `$file` the host tools' blocks of the replaced one it does not hold yet.
     *
     * @throws \RuntimeException when the file cannot be written
     */
    private function keepHostLines(string $file): void
    {
        $new = "{$this->app}/{$file}";
        preg_match_all(self::HOST_BLOCK, Files::read("{$this->previous}/{$file}") ?? '', $blocks);
        $content = Files::read($new);
        if ($content === null) {
            return;
        }
        $missing = array_filter($blocks[0], static fn(string $block): bool => !str_contains($content, $block));
        if ($missing !== []) {
            Files::writeAtomically($new, rtrim($content) . "\n\n" . implode("\n\n", $missing) . "\n");
        }
    }

    /** Whether anything is at the path, a dangling link included. */
    private static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }
}
