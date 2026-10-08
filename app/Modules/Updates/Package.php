<?php

declare(strict_types=1);

namespace App\Modules\Updates;

use App\Modules\Updates\Exceptions\UpdateRefusedException;

/**
 * A release's zip unpacked into a folder of the updater's (Workspace::release()), with nothing in it taken beyond what
 * the manifest signed: every entry under the zip's one top folder (amobot-<version>/) and inside one of the release's
 * top-level paths — none absolute, none climbing out with `..`, none spelled with a backslash or a drive (zip-slip), no
 * link —, each written as a plain file of the app's (Disk), its storage/ left out (a shop's own is never replaced), the
 * whole held to a size and a count. The zip is checked whole before a byte of it is written (check()), then unpacked a
 * slice at a time (extract()), as far as a host's time limit allows.
 */
final class Package
{
    /** The most a release unpacks to, and its most entries: one is about a hundred MB in some ten thousand files. */
    public const MAX_UNPACKED = 600 * 1024 * 1024;
    public const MAX_ENTRIES = 60000;

    public const NOT_A_RELEASE = 'بسته این نسخه فایل‌هایی دارد که جای آن‌ها بیرون از برنامه است؛ نصب نمی‌شود.';

    public const UNREADABLE = 'بسته این نسخه باز نمی‌شود؛ به‌روزرسانی را لغو کنید و دوباره شروع کنید.';

    /** A link, in an entry's attributes (a Unix file type): never one of a release's. */
    private const LINK = 0o120000;

    private const CHUNK = 65536;

    private ?\ZipArchive $zip = null;

    public function __construct(
        private readonly string $file,
        private readonly Manifest $manifest,
    ) {}

    /**
     * How many entries the zip has.
     *
     * @throws UpdateRefusedException when it is no zip
     */
    public function count(): int
    {
        return $this->zip()->count();
    }

    /**
     * Every entry where it may go, none a link, and the whole no bigger than MAX_UNPACKED in no more than MAX_ENTRIES.
     *
     * @throws UpdateRefusedException
     */
    public function check(): void
    {
        $zip = $this->zip();
        if ($zip->count() > self::MAX_ENTRIES) {
            throw UpdateRefusedException::refused(self::NOT_A_RELEASE);
        }
        $size = 0;
        for ($index = 0; $index < $zip->count(); $index++) {
            $stat = $zip->statIndex($index);
            $this->target($stat === false ? '' : $stat['name']);
            if ($zip->getExternalAttributesIndex($index, $system, $attributes) && $system === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0o170000) === self::LINK) {
                throw UpdateRefusedException::refused(self::NOT_A_RELEASE);
            }
            $size += $stat === false ? 0 : $stat['size'];
        }
        if ($size > self::MAX_UNPACKED) {
            throw UpdateRefusedException::refused(self::NOT_A_RELEASE);
        }
    }

    /**
     * The entries from `$from` on unpacked into `$folder` — at least one, and more while `$until` (microtime) has not
     * come: the number of the next entry, count() once every one is.
     *
     * @throws UpdateRefusedException when an entry cannot be read or written, or may not go where it says
     */
    public function extract(string $folder, int $from, float $until): int
    {
        $zip = $this->zip();
        for ($index = $from; $index < $zip->count(); $index++) {
            if ($index > $from && microtime(true) >= $until) {
                return $index;
            }
            $stat = $zip->statIndex($index);
            $target = $this->target($stat === false ? '' : $stat['name']);
            if ($stat === false || $target === null) {
                continue;
            }
            try {
                if (str_ends_with($stat['name'], '/')) {
                    Disk::folder("{$folder}/{$target}");
                } else {
                    $this->unpack($index, $stat['size'], "{$folder}/{$target}");
                }
            } catch (\RuntimeException $e) {
                throw $e instanceof UpdateRefusedException ? $e : UpdateRefusedException::refused(Workspace::UNWRITABLE);
            }
        }

        return $zip->count();
    }

    /**
     * The path inside the release where an entry goes; null for one left out — the zip's top folder itself, storage/.
     *
     * @throws UpdateRefusedException when it may not go anywhere: outside the release, or outside its paths
     */
    private function target(string $name): ?string
    {
        $parts = explode('/', rtrim($name, '/'));
        $outside = $name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1
            || array_intersect($parts, ['', '.', '..']) !== [] || $parts[0] !== "amobot-{$this->manifest->version}"
            || (isset($parts[1]) && $parts[1] !== 'storage' && !in_array($parts[1], $this->manifest->paths, true));
        if ($outside) {
            throw UpdateRefusedException::refused(self::NOT_A_RELEASE);
        }

        return !isset($parts[1]) || $parts[1] === 'storage' ? null : implode('/', array_slice($parts, 1));
    }

    /**
     * The entry's bytes into the file `$path`: all `$size` of them, and no more.
     *
     * @throws UpdateRefusedException when the entry cannot be read whole
     * @throws \RuntimeException when the file cannot be written
     */
    private function unpack(int $index, int $size, string $path): void
    {
        Disk::folder(dirname($path));
        $from = $this->zip()->getStreamIndex($index);
        if ($from === false) {
            throw UpdateRefusedException::refused(self::UNREADABLE);
        }
        $to = Disk::create($path);
        $written = 0;
        try {
            while (!feof($from) && $written <= $size) {
                $chunk = fread($from, self::CHUNK);
                if ($chunk === false) {
                    break;
                }
                Disk::write($to, $chunk);
                $written += strlen($chunk);
            }
        } finally {
            fclose($from);
            fclose($to);
        }
        if ($written !== $size) {
            throw UpdateRefusedException::refused(self::UNREADABLE);
        }
    }

    /** @throws UpdateRefusedException when the file is no zip */
    private function zip(): \ZipArchive
    {
        if ($this->zip === null) {
            $zip = new \ZipArchive();
            if ($zip->open($this->file, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) {
                throw UpdateRefusedException::refused(self::UNREADABLE);
            }
            $this->zip = $zip;
        }

        return $this->zip;
    }
}
