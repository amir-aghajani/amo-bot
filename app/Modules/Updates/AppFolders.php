<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\Files;

/**
 * The app's own top-level paths — the folders and files a release replaces: app/, vendor/, public/, …, the root's
 * .htaccess — swapped for a release's, and back. A folder is moved, never copied: aside into the updater's previous/,
 * the release's into its place — each a rename on one file system, at once however many files it holds. A file
 * (composer.json, the root's .htaccess) is copied aside and replaced in one rename, so it is never missing. Both ways
 * read where each path stands off the disk itself — the release's copy still in its folder or not, the app's in
 * previous/ or not —, so an install a crash cut short is finished (install()) or taken back (restore()) from wherever it
 * stopped, by whichever code runs then. config.php and storage/ are no path of a release's (Manifest). What a host's
 * own tools wrote into the .htaccess files a release replaces — the PHP version and settings cPanel's MultiPHP tools
 * keep there, in the document root's — goes on into the release's (HOST_FILES): without it the shop could wake up on
 * another PHP.
 */
final class AppFolders
{
    /** The .htaccess files a release replaces that a host's tools write into, by the path of the release that brings each. */
    private const HOST_FILES = ['.htaccess' => '.htaccess', 'public' => 'public/.htaccess'];

    /** A block a host's tool wrote: from its «# … BEGIN cPanel-generated …» line to its «# … END cPanel-generated …» one. */
    private const HOST_BLOCK = '/^#[^\r\n]*BEGIN cPanel-generated[^\r\n]*\R.*?^#[^\r\n]*END cPanel-generated[^\r\n]*/ms';

    public function __construct(
        /** The app's folder (Application::basePath()). */
        private readonly string $app,
        /** Where the app's paths a release replaced are kept (Workspace::previous()). */
        private readonly string $previous,
    ) {}

    /**
     * The release's paths in the app's place, the app's own kept aside — those still to swap, when some are already —, the
     * host's own lines kept in the .htaccess files among them.
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
     * The app's own paths back in their place, the release's back in its folder — as far as install() took them.
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
     * Which of the app's paths PHP may not change — '.' for the app's folder itself: one not its own (another account's,
     * PHP running as the web server's user), or one it may not write; null when it may change them all.
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

    /**
     * Whether a folder moves from the updater's folder into the app's and back: the two on one file system, both PHP's to
     * write in — what install() does to every path, tried on one of nothing.
     */
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

    /** Whether the version a release replaced is kept aside, to put back: what every version has (Manifest::ESSENTIAL). */
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
     * The blocks the host's tools wrote into the app's `$file` before the swap (kept aside), at the end of the release's —
     * those it does not hold already, so a second go adds nothing.
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

    /** Whether there is something at the path: a file, a folder, or a link — even one to nothing. */
    private static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }
}
