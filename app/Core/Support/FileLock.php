<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * A lock between processes using only a file (shared hosting has no lock server): the scheduler's run, the poller, and
 * config.php's writers. It is the operating system's lock, so a process that dies lets go of it.
 */
final class FileLock
{
    /** @param resource $handle */
    private function __construct(private $handle) {}

    /**
     * Take the lock on `$path` (making the file and folder) if nobody holds it; null when another process does.
     *
     * @throws \RuntimeException when there is no file to lock: its folder is not writable
     */
    public static function take(string $path): ?self
    {
        $handle = self::open($path) ?? throw new \RuntimeException("Cannot lock {$path}: its folder is not writable.");
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    /**
     * Wait for the lock on `$path` (making the file and folder); null when its folder is not writable.
     */
    public static function wait(string $path): ?self
    {
        $handle = self::open($path);
        if ($handle === null) {
            return null;
        }
        flock($handle, LOCK_EX);

        return new self($handle);
    }

    /** Let go; safe to call again. */
    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    /** @return resource|null The lock's file, opened (made if missing); null when its folder is not writable */
    private static function open(string $path)
    {
        $handle = Files::writableDirectory(dirname($path)) ? @fopen($path, 'c') : false;

        return $handle === false ? null : $handle;
    }
}
