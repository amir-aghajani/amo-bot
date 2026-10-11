<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Core\Support\FileLock;
use App\Core\Support\Files;
use App\Modules\Updates\Exceptions\UpdateRefusedException;

/**
 * The updater's folder, storage/updates: the run (state.json) and its lock (one request at a time), download/, the
 * unpacked release (<version>/), and previous/ — the replaced paths, kept for a rollback until the next update installs.
 */
final class Workspace
{
    public const UNWRITABLE = 'پوشه storage/updates نوشته نمی‌شود؛ فضای خالی هاست و اجازه نوشتن در پوشه storage را بررسی کنید.';

    public const BUSY = 'یک مرحله از به‌روزرسانی همین حالا در جریان است؛ چند لحظه دیگر دوباره امتحان کنید.';

    private const STATE = 'state.json';
    private const LOCK = 'state.lock';
    private const DOWNLOAD = 'download';
    private const PREVIOUS = 'previous';

    public function __construct(private readonly string $folder) {}

    /**
     * The run's lock: one request works the update at a time.
     *
     * @throws UpdateRefusedException while another request holds it, or when the folder cannot be written
     */
    public function lock(): FileLock
    {
        try {
            $lock = FileLock::take($this->folder . '/' . self::LOCK);
        } catch (\RuntimeException) {
            throw UpdateRefusedException::refused(self::UNWRITABLE);
        }

        return $lock ?? throw UpdateRefusedException::busy(self::BUSY);
    }

    /** The run kept; null when there is none. */
    public function run(): ?UpdateRun
    {
        return UpdateRun::fromKept(json_decode(Files::read($this->folder . '/' . self::STATE) ?? '', true));
    }

    /**
     * Save the run atomically.
     *
     * @throws UpdateRefusedException when it cannot be written
     */
    public function save(UpdateRun $run): void
    {
        try {
            Files::writeAtomically($this->folder . '/' . self::STATE, json_encode($run->kept(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\RuntimeException) {
            throw UpdateRefusedException::refused(self::UNWRITABLE);
        }
    }

    /** Delete the run. */
    public function forget(): void
    {
        Files::delete($this->folder . '/' . self::STATE);
    }

    /** Where a file of the release is fetched to. */
    public function download(string $name): string
    {
        return $this->folder . '/' . self::DOWNLOAD . '/' . $name;
    }

    /** Where release `$version` is unpacked, and where its paths return when an install is taken back. */
    public function release(string $version): string
    {
        return $this->folder . '/' . $version;
    }

    /** Where the app's paths an install replaced are kept. */
    public function previous(): string
    {
        return $this->folder . '/' . self::PREVIOUS;
    }

    /**
     * Delete everything but the run and `$keep` (download/, previous/, a release's folder), as much as goes before
     * `$until` (microtime).
     *
     * @param list<string> $keep
     * @return bool Whether it is all gone
     * @throws UpdateRefusedException when something of it cannot be deleted
     */
    public function clear(array $keep, float $until): bool
    {
        try {
            foreach (is_dir($this->folder) ? new \DirectoryIterator($this->folder) : [] as $entry) {
                if (!$entry->isDot() && !in_array($entry->getFilename(), [self::STATE, self::LOCK, ...$keep], true) && !Disk::delete($entry->getPathname(), $until)) {
                    return false;
                }
            }
        } catch (\RuntimeException) {
            throw UpdateRefusedException::refused(self::UNWRITABLE);
        }

        return true;
    }

    /** The bytes free on the disk the folder is on; null when the host does not say. */
    public function freeSpace(): ?float
    {
        return Files::freeSpace($this->folder);
    }
}
