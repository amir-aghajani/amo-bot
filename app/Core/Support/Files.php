<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * How the app writes its own files (config.php, host keys, scheduler state, throttle counts, kept pictures, sessions,
 * the log). They are owner-only (0600, folders 0700) where PHP runs as the account owning the app — config.php holds
 * the database password and APP_KEY, and a shared host has other accounts. Under mod_php, PHP is another user and the
 * owner must still read the host keys and config.php in the file manager, so files are 0644 there (ownerOnly(), decided
 * at boot). A refusing file system is an answer here (false, null), never a PHP warning that could leak into an answer.
 */
final class Files
{
    /** A file's mode while PHP runs as the account that owns the app. */
    public const FILE_MODE = 0o600;

    /** A folder's mode then. */
    public const FOLDER_MODE = 0o700;

    /** A file's mode where PHP runs as another user (mod_php). */
    public const SHARED_FILE_MODE = 0o644;

    /** A folder's mode then. */
    public const SHARED_FOLDER_MODE = 0o755;

    /** Whether new files are owner-only; true until the app boots and decides. */
    private static bool $ownerOnly = true;

    /** Whether the files and folders made from now on are owner-only or readable by the account (set at boot). */
    public static function ownerOnly(bool $ownerOnly): void
    {
        self::$ownerOnly = $ownerOnly;
    }

    /**
     * Whether the process runs as the user that owns `$folder`. True without posix (Windows) and when the owner cannot
     * be read, so files stay owner-only.
     */
    public static function processOwns(string $folder): bool
    {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        $owner = @fileowner($folder);

        return $owner === false || $owner === posix_geteuid();
    }

    /** The mode a file the app makes takes (ownerOnly()). */
    public static function fileMode(): int
    {
        return self::$ownerOnly ? self::FILE_MODE : self::SHARED_FILE_MODE;
    }

    /** The mode a folder the app makes takes (ownerOnly()). */
    public static function folderMode(): int
    {
        return self::$ownerOnly ? self::FOLDER_MODE : self::SHARED_FOLDER_MODE;
    }

    /** Whether the folder is there (made, with its parents, when it is not — folderMode()) and the app may write in it. */
    public static function writableDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            @mkdir($directory, self::folderMode(), true);
        }

        return is_dir($directory) && is_writable($directory);
    }

    /**
     * Write the file whole or not at all: to a temporary file beside it, then renamed over it, so no reader sees half of
     * it. The temporary file is owner-only before its first byte, then takes the old file's mode (or fileMode()). A full
     * disk that writes only part of it is a failure too.
     *
     * @throws \RuntimeException when it cannot be written; the file is then as it was
     */
    public static function writeAtomically(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!self::writableDirectory($directory)) {
            throw new \RuntimeException("Cannot write {$path}: {$directory} is not writable.");
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $mode = is_file($path) ? fileperms($path) & 0o777 : self::fileMode();
        $handle = @fopen($temporary, 'x');
        if ($handle !== false) {
            @chmod($temporary, self::FILE_MODE);
        }
        // A full disk writes part of it and says how much: that is not the file either.
        $written = $handle !== false && @fwrite($handle, $content) === strlen($content) && @fflush($handle);
        if ($handle !== false) {
            fclose($handle);
            @chmod($temporary, $mode);
        }
        if (!$written || !@rename($temporary, $path)) {
            self::delete($temporary);

            throw new \RuntimeException("Cannot write {$path}.");
        }
    }

    /**
     * Read and rewrite the file under an exclusive lock (making it if missing), so two processes change it one after the
     * other: `$change` gets the content and returns the next.
     *
     * @param \Closure(string): string $change
     * @return string What it holds now
     * @throws \RuntimeException when it cannot be opened
     */
    public static function rewrite(string $path, \Closure $change): string
    {
        $made = !is_file($path);
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$path}.");
        }
        if ($made) {
            @chmod($path, self::fileMode());
        }

        try {
            flock($handle, LOCK_EX);
            $content = $change((string) stream_get_contents($handle));
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $content);
            fflush($handle);

            return $content;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** What the file holds; null when it is not there (or may not be read). */
    public static function read(string $path): ?string
    {
        $content = is_file($path) ? @file_get_contents($path) : false;

        return $content === false ? null : $content;
    }

    /**
     * Read a file that rewrite() keeps, under a shared lock, so never half rewritten (a rewrite empties it first). Null
     * when it is not there or may not be read.
     */
    public static function readShared(string $path): ?string
    {
        $handle = is_file($path) ? @fopen($path, 'r') : false;
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_SH);
            $content = stream_get_contents($handle);

            return $content === false ? null : $content;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** What the PHP file returns; null when it is not there or does not parse. */
    public static function load(string $path): mixed
    {
        try {
            $value = is_file($path) ? @include $path : false;
        } catch (\ParseError) {
            return null;
        }

        return $value === false ? null : $value;
    }

    /** When the file was last written (unix seconds); null when it is not there. */
    public static function modifiedAt(string $path): ?int
    {
        $time = @filemtime($path);

        return $time === false ? null : $time;
    }

    /** Delete the file if it is there; false only when it is still there. */
    public static function delete(string $path): bool
    {
        return !is_file($path) || @unlink($path);
    }

    /**
     * The bytes free on the disk the path (or its nearest existing parent) is on; null when the host does not say
     * (disk_free_space() disabled, a path outside open_basedir).
     */
    public static function freeSpace(string $path): ?float
    {
        while (!is_dir($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }
            $path = $parent;
        }
        $free = function_exists('disk_free_space') ? @disk_free_space($path) : false;

        return $free === false ? null : $free;
    }
}
