<?php

declare(strict_types=1);

namespace App\Modules\Updates;

/**
 * What the updater does on the disk — moving the app's folders, writing what it downloads and unpacks, deleting what it
 * no longer needs —, the file system's no an exception with its reason, never a PHP warning (Core\Support\Files does
 * the same for the app's own files). What it puts in the app's place is the web server's to read, as an upload
 * extracted in the host's file manager is: folders FOLDER_MODE, files FILE_MODE — the panels' pages, its .htaccess.
 */
final class Disk
{
    /** A folder of the app's: its owner's to change, everyone's to enter — the web server serves public/ from it. */
    public const FOLDER_MODE = 0o755;

    /** A file of the app's: its owner's to change, everyone's to read. */
    public const FILE_MODE = 0o644;

    /**
     * `$from` under the name `$to` — on one file system, at once: a folder whole, a file in place of the one there.
     *
     * @throws \RuntimeException when it cannot be moved
     */
    public static function move(string $from, string $to): void
    {
        self::quietly(static fn(): bool => rename($from, $to), "Cannot move {$from} to {$to}");
    }

    /**
     * A copy of the file `$from` at `$to`, with its permissions — written beside it first, so `$to` is the whole of it or
     * what it was.
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
     * The folder, made with its parents (FOLDER_MODE) when it is not there.
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
     * The file opened to write, made new (FILE_MODE) or emptied.
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
     * `$bytes` written to the open file, every one of them.
     *
     * @param resource $handle
     * @throws \RuntimeException when they are not: the disk full, the file gone
     */
    public static function write($handle, string $bytes): void
    {
        self::quietly(static fn(): bool => fwrite($handle, $bytes) === strlen($bytes), 'Cannot write all of it');
    }

    /**
     * The file or folder gone, with everything in it — as much of it as goes before `$until` (microtime); a folder of
     * many files (a release's vendor/) may take a few calls.
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
     * What `$work` gives, PHP's warnings on the way caught: false from it is the file system's no.
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
