<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Core\Exceptions\ValidationException;
use App\Core\Support\Files;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Telegram\Messages;

/**
 * A backup file the owner uploads, told by its bytes, never its name: a zip (Faoxima's own backup.zip — backup.sql
 * beside the bot's files —, an export zipped), a gzip, or plain SQL. inspect() checks it holds a dump before it is
 * kept; unpack() writes the dump out plain, a step at a time, within a size and the disk's free space.
 */
final class BackupFile
{
    public const SQL = 'sql';
    public const ZIP = 'zip';
    public const GZIP = 'gz';

    /** The most a backup may unpack to, bytes: a bot's database is far smaller, and a file that unpacks beyond is a bomb. */
    public const MOST = 1_073_741_824;

    /** The free space keeping and unpacking a backup leave on the disk, bytes. */
    public const KEEP_FREE = 268_435_456;

    /** The dump at the root of Faoxima's backup. */
    private const FAOXIMA_DUMP = 'backup.sql';

    /** How much of the dump inspect() reads to tell it is one. */
    private const HEAD = 65_536;

    /** How much one read of unpack() takes. */
    private const PIECE = 1_048_576;

    /**
     * What the file at `$path` is, checked to hold a dump, and — a zip — which of its files the dump is: backup.sql at
     * its root, else its largest .sql file.
     *
     * @return array{string, string|null}
     * @throws ValidationException under `file`
     */
    public static function inspect(string $path): array
    {
        $magic = (string) @file_get_contents($path, false, null, 0, 4);
        $entry = null;
        if (str_starts_with($magic, "PK\x03\x04") || str_starts_with($magic, "PK\x05\x06")) {
            $kind = self::ZIP;
            $zip = self::zip($path);
            try {
                $entry = self::entry($zip);
                if ($entry === null) {
                    throw ImportRefusedException::on('file', ImportRefusedException::NO_SQL_IN_ZIP);
                }
                $stream = $zip->getStream($entry);
                $head = $stream === false ? '' : (string) @stream_get_contents($stream, self::HEAD);
                if ($stream !== false) {
                    fclose($stream);
                }
            } finally {
                $zip->close();
            }
        } elseif (str_starts_with($magic, "\x1F\x8B")) {
            $kind = self::GZIP;
            $gzip = @gzopen($path, 'rb');
            $head = $gzip === false ? '' : (string) @gzread($gzip, self::HEAD);
            if ($gzip !== false) {
                gzclose($gzip);
            }
        } else {
            $kind = self::SQL;
            $head = (string) @file_get_contents($path, false, null, 0, self::HEAD);
        }

        if (!DumpReader::opensLikeSql($head)) {
            throw ImportRefusedException::on('file', ImportRefusedException::NOT_A_DUMP);
        }

        return [$kind, $entry];
    }

    /**
     * Unpack the dump of the file at `$path` — the zip's `$entry`, or what the gzip holds — to `$to`, a piece at a time
     * until the budget is spent; true once it is whole. The pieces go to a `.part` beside it, which the next step goes
     * on with — what an earlier step wrote (or a step that was killed) is passed over in the file, never written again
     * —, renamed once whole. It never grows past `$most`, nor leaves less than `$keepFree` free on the disk (on a shared
     * host the databases often share its quota); a refused one is deleted.
     *
     * @throws ValidationException under `file`: the file is broken, unpacks too large, or the host has no room for it
     */
    public static function unpack(string $path, string $kind, ?string $entry, string $to, Budget $budget, int $most, int $keepFree): bool
    {
        $part = $to . '.part';
        $written = is_file($part) ? (int) filesize($part) : 0;
        $zip = $kind === self::ZIP ? self::zip($path) : null;
        $in = $out = false;

        try {
            $declared = $zip !== null && $entry !== null ? $zip->statName($entry) : false;
            if ($declared !== false && $declared['size'] > $most) {
                throw self::tooLarge($most);
            }
            $in = match (true) {
                $zip !== null && $entry !== null => $zip->getStream($entry),
                $kind === self::GZIP => @gzopen($path, 'rb'),
                default => false,
            };
            if ($in === false) {
                throw ImportRefusedException::on('file', ImportRefusedException::BROKEN);
            }
            $out = @fopen($part, 'ab');
            if ($out === false) {
                throw new \RuntimeException("The dump cannot be written at {$part}.");
            }
            @chmod($part, Files::fileMode());

            // What is written already, passed over: inflating it again costs a moment, writing it again the disk.
            for ($left = $written; $left > 0; $left -= strlen($piece)) {
                $piece = @stream_get_contents($in, min(self::PIECE, $left));
                if ($piece === false || $piece === '') {
                    throw ImportRefusedException::on('file', ImportRefusedException::BROKEN);
                }
            }
            while (($piece = @stream_get_contents($in, self::PIECE)) !== '') {
                // A zip's piece fails its checksum, a gzip's its inflating.
                if ($piece === false) {
                    throw ImportRefusedException::on('file', ImportRefusedException::BROKEN);
                }
                $written += strlen($piece);
                if ($written > $most) {
                    throw self::tooLarge($most);
                }
                $free = Files::freeSpace($part);
                if (($free !== null && $free - strlen($piece) < $keepFree) || @fwrite($out, $piece) !== strlen($piece)) {
                    throw ImportRefusedException::on('file', ImportRefusedException::NO_SPACE);
                }
                if ($budget->spent()) {
                    @fflush($out);
                    self::close($in, $out, $zip);

                    return false;
                }
            }
            if (!@fflush($out)) {
                throw ImportRefusedException::on('file', ImportRefusedException::NO_SPACE);
            }
        } catch (\Throwable $e) {
            self::close($in, $out, $zip);
            Files::delete($part);

            throw $e;
        }
        self::close($in, $out, $zip);
        if (!@rename($part, $to)) {
            Files::delete($part);

            throw new \RuntimeException("The dump cannot be written at {$to}.");
        }

        return true;
    }

    private static function tooLarge(int $most): ValidationException
    {
        return ImportRefusedException::on('file', sprintf(ImportRefusedException::UNPACKS_TOO_LARGE, Messages::bytes($most)));
    }

    /** @throws ValidationException under `file`: the host cannot open a zip, or this one is broken */
    private static function zip(string $path): \ZipArchive
    {
        if (!extension_loaded('zip')) {
            throw ImportRefusedException::on('file', ImportRefusedException::NO_ZIP);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw ImportRefusedException::on('file', ImportRefusedException::BROKEN);
        }

        return $zip;
    }

    /** The zip's dump: backup.sql at its root, else its largest .sql file (never a Mac's `._` shadow of one); null for none. */
    private static function entry(\ZipArchive $zip): ?string
    {
        $largest = null;
        $size = -1;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = $stat['name'];
            if (strcasecmp($name, self::FAOXIMA_DUMP) === 0) {
                return $name;
            }
            if (str_ends_with(strtolower($name), '.sql') && !str_starts_with(basename($name), '._') && $stat['size'] > $size) {
                [$largest, $size] = [$name, $stat['size']];
            }
        }

        return $largest;
    }

    /**
     * @param resource|false $in
     * @param resource|false $out
     */
    private static function close($in, $out, ?\ZipArchive $zip): void
    {
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        $zip?->close();
    }
}
