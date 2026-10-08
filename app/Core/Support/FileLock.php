<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * "Only one of us at a time" between processes, with nothing but a file — shared hosting has no lock server: the
 * scheduler's run (the cron, the /cron address and the poller's tick never overlap), the poller itself (one process
 * talks to Telegram), and config.php's writers, each in its turn. The lock is the operating system's, so a process that
 * dies lets go of it with its last breath.
 */
final class FileLock
{
    /** @param resource $handle */
    private function __construct(private $handle) {}

    /**
     * The lock on `$path` (the file and its folder made when missing) when nobody holds it; null when another process
     * does.
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
     * The lock on `$path` (the file and its folder made when missing), once whoever holds it lets go — writers that each
     * must have their turn; null when there is no file to lock: its folder is not writable.
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

    /** @return resource|null The lock's file, opened — made, and its folder, when missing; null when its folder is not writable */
    private static function open(string $path)
    {
        $handle = Files::writableDirectory(dirname($path)) ? @fopen($path, 'c') : false;

        return $handle === false ? null : $handle;
    }
}
