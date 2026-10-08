<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * The few things the app does with files of its own (config.php, the host keys, the scheduler's state, the sign-in
 * throttle, the pictures it keeps, the sessions, the log), done one way. They are their owner's alone — a file made
 * readable and writable by the user PHP runs as and nobody else, a folder entered by it alone —: config.php holds the
 * database's password, the bot's token and the key to what is kept encrypted, a host key opens the installer or the
 * panel, a picture is a customer's, and a shared host has other accounts. That holds where PHP runs as the account that
 * owns the app (PHP-FPM, suEXEC, LSAPI — the common case); where it runs as another user (mod_php), the owner opens
 * install-key.txt, recovery-key.txt and config.php in the host's file manager as that account, so what the app makes
 * is theirs to read there (ownerOnly(): decided as the app boots, by who owns its folder) — on such a host every site's
 * PHP is that one user anyway: an owner-only mode would protect nothing and only lock the owner out. A file system that
 * says no is an answer here, never a PHP warning: on a shared host a folder the web server may not write is common, and
 * a warning would end up in an answer or a test's output.
 */
final class Files
{
    /** What a file the app makes is while PHP runs as the account that owns the app: its owner's to read and write, nobody else's. */
    public const FILE_MODE = 0o600;

    /** What a folder the app makes is then: its owner's to enter, list and write in. */
    public const FOLDER_MODE = 0o700;

    /** What a file the app makes is where PHP runs as another user: theirs to write, the account's — anyone's — to read. */
    public const SHARED_FILE_MODE = 0o644;

    /** What a folder the app makes is then: the account's to enter and list. */
    public const SHARED_FOLDER_MODE = 0o755;

    /** Whether what the app makes is its owner's alone (ownerOnly()) — until the app boots and says, it is. */
    private static bool $ownerOnly = true;

    /**
     * Whether the files and folders the app makes from now on are its owner's alone (FILE_MODE, FOLDER_MODE) or the
     * account's to read (SHARED_FILE_MODE, SHARED_FOLDER_MODE) — the Application says, as it boots, by processOwns().
     */
    public static function ownerOnly(bool $ownerOnly): void
    {
        self::$ownerOnly = $ownerOnly;
    }

    /**
     * Whether the process runs as the user that owns `$folder` (the app's): PHP-FPM, suEXEC, LSAPI, a shell — or the host
     * keeps no users apart (no posix: Windows), and it is as if it did. A folder whose owner cannot be read counts as
     * its, and what the app makes stays owner-only.
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
     * Write the file whole or not at all: the content goes to a file of its own beside it, which then takes its name, so
     * a reader never sees half of it — half a state file reads as "nothing ever ran", half a config.php as a broken shop.
     * That file is its owner's alone before its first byte is written; then it takes the permissions the file had (an
     * owner who made theirs readable to their group keeps it so), or a new one's (fileMode()) — wherever the host lets a
     * file's permissions be set at all (permissions it cannot set are not what keeps the file from being written).
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
     * Read and rewrite the file under an exclusive lock — made, empty and as the app's files are (fileMode()), when it is
     * not there — so two processes changing it at once change it one after the other: `$change` gets what it holds and
     * says what it holds next.
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
     * What a file rewrite() keeps holds, read under a shared lock: never what a rewrite in another process has half
     * written — it is emptied first —, nor, where a lock bars every other reader (Windows), nothing in its place. Null
     * when it is not there (or may not be read).
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

    /** What the PHP file returns; null when it is not there (gone meanwhile, too) or does not parse. */
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

    /** The file gone, if it was there; true unless it is still there. */
    public static function delete(string $path): bool
    {
        return !is_file($path) || @unlink($path);
    }

    /**
     * The bytes free on the disk a path is on — the path, or the nearest folder above it that is there (a folder not made
     * yet is made on that disk) —; null when the host does not say (disk_free_space() disabled, a path outside
     * open_basedir).
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
