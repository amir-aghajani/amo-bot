<?php

declare(strict_types=1);

namespace App\Modules\Updates;

/**
 * The updater's file operations: a refusal is a RuntimeException with PHP's reason, never a warning. What it puts in the
 * app's place must be readable by the web server, as an upload extracted in a file manager is: 0755 folders, 0644 files.
 */
final class Disk
{
    /** A release folder's mode: the web server serves public/ from it. */
    public const FOLDER_MODE = 0o755;

    /** A release file's mode. */
    public const FILE_MODE = 0o644;

    /**
     * Rename `$from` to `$to` (a folder whole, a file over the one there).
     *
     * @throws \RuntimeException when it cannot be moved
     */
    public static function move(string $from, string $to): void
    {
        self::quietly(static fn(): bool => rename($from, $to), "Cannot move {$from} to {$to}");
    }

    /**
     * Copy a file with its permissions, through a temporary file, so `$to` is either whole or as it was.
     *
     * @throws \RuntimeException when it cannot be copied
     */
    public static function copy(string $from, string $to): void
    {
        $temporary = $to . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            self::quietly(static fn(): bool => copy($from, $temporary) && chmod($temporary, fileperms($from) & 0o777), "Cannot copy {$from} to {$to}");
            self::move($temporary, $to);
        } finally {
            if (is_file($temporary)) {
                self::quietly(static fn(): bool => unlink($temporary), "Cannot delete {$temporary}");
            }
        }
    }

    /**
     * Make the folder and its parents when missing.
     *
     * @throws \RuntimeException when it cannot be made
     */
    public static function folder(string $path): void
    {
        if (!is_dir($path)) {
            self::quietly(static fn(): bool => mkdir($path, self::FOLDER_MODE, true) || is_dir($path), "Cannot make {$path}");
            self::quietly(static fn(): bool => chmod($path, self::FOLDER_MODE), "Cannot set the permissions of {$path}");
        }
    }

    /**
     * Open the file for writing, made new or emptied.
     *
     * @return resource
     * @throws \RuntimeException when it cannot be
     */
    public static function create(string $path)
    {
        $handle = self::quietly(static fn() => fopen($path, 'wb'), "Cannot write {$path}");
        self::quietly(static fn(): bool => chmod($path, self::FILE_MODE), "Cannot set the permissions of {$path}");

        return $handle;
    }

    /**
     * Write all of `$bytes` to the open file.
     *
     * @param resource $handle
     * @throws \RuntimeException when they are not: the disk full, the file gone
     */
    public static function write($handle, string $bytes): void
    {
        self::quietly(static fn(): bool => fwrite($handle, $bytes) === strlen($bytes), 'Cannot write all of it');
    }

    /**
     * Delete the file or folder recursively, as much as goes before `$until` (microtime): a big folder (vendor/) may
     * take a few calls. Links are deleted, never followed.
     *
     * @return bool Whether it is gone
     * @throws \RuntimeException when something of it cannot be deleted
     */
    public static function delete(string $path, float $until): bool
    {
        if (is_link($path) || is_file($path)) {
            self::quietly(static fn(): bool => unlink($path), "Cannot delete {$path}");

            return true;
        }
        if (!is_dir($path)) {
            return true;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $name = $item->getPathname();
            // A link is deleted, never followed: what it points at is not the folder's.
            if ($item->isDir() && !$item->isLink()) {
                self::quietly(static fn(): bool => rmdir($name), "Cannot delete {$name}");
            } else {
                self::quietly(static fn(): bool => unlink($name), "Cannot delete {$name}");
            }
            if (microtime(true) >= $until) {
                return false;
            }
        }
        self::quietly(static fn(): bool => rmdir($path), "Cannot delete {$path}");

        return true;
    }

    /**
     * Run `$work` with warnings caught; false from it is a failure.
     *
     * @template T
     * @param \Closure(): (T|false) $work
     * @return T
     * @throws \RuntimeException `$failure` and the reason PHP gave
     */
    private static function quietly(\Closure $work, string $failure): mixed
    {
        $reason = null;
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });
        try {
            $result = $work();
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw new \RuntimeException($failure . ($reason === null ? '.' : ": {$reason}"));
        }

        return $result;
    }
}
