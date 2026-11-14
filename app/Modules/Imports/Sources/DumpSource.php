<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Core\Exceptions\ValidationException;
use App\Core\Security\Encrypter;
use App\Core\Support\Files;
use App\Modules\Backups\Backups;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Models\Import;
use App\Modules\Telegram\Messages;
use Illuminate\Database\Connection;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * A backup file as the source: kept in the import's folder as uploaded (keep()), unpacked to a plain dump.sql a step at
 * a time, then read on a step at a time from where the step before stopped (stage()) — a statement's byte offset and
 * the rows of it staged already, saved in `imports.reading` with each batch of rows, so a step stopped anywhere stages
 * no row twice. Every table seen goes to `imports.schema` with its columns as the reading comes to it.
 */
final class DumpSource
{
    /** The plain dump in the import's folder. */
    private const DUMP = 'dump.sql';

    /** The file as uploaded, until it is unpacked. */
    private const UPLOAD = 'upload';

    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
        /** The most a backup may unpack to, bytes */
        private readonly int $unpackedMost = BackupFile::MOST,
        /** The free space keeping and unpacking leave on the disk, bytes */
        private readonly int $keepFree = BackupFile::KEEP_FREE,
    ) {}

    /**
     * Keep the file uploaded for `$import` in its folder, checked by its bytes to be a dump — plain, zipped or gzipped.
     *
     * @throws ValidationException under `file`
     */
    public function keep(Import $import, string $folder, ?UploadedFileInterface $file): void
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            throw ImportRefusedException::on('file', ImportRefusedException::NO_FILE);
        }
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw ImportRefusedException::on('file', sprintf(ImportRefusedException::TOO_LARGE, Messages::bytes(Backups::uploadLimit())));
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ImportRefusedException::on('file', $file->getError() === UPLOAD_ERR_PARTIAL ? ImportRefusedException::BROKEN : ImportRefusedException::NOT_A_DUMP);
        }

        $upload = $folder . '/' . self::UPLOAD;
        $this->copy($file, $upload);
        try {
            [$kind, $entry] = BackupFile::inspect($upload);
        } catch (ValidationException $e) {
            Files::delete($upload);

            throw $e;
        }
        // A plain dump is read as it is.
        $dump = $folder . '/' . self::DUMP;
        if ($kind === BackupFile::SQL && !@rename($upload, $dump)) {
            throw new \RuntimeException("The upload cannot be kept at {$dump}.");
        }

        $import->file_name = self::fileName((string) $file->getClientFilename());
        $import->reading = ['kind' => $kind, 'entry' => $entry, 'offset' => 0, 'skip' => 0, 'size' => $kind === BackupFile::SQL ? (int) filesize($dump) : null];
        $import->save();
    }

    /**
     * Read on from where the import's reading stopped, staging the rows of every table the migrator reads, until the
     * dump is through (true) or the budget is spent.
     *
     * @throws ValidationException under `file`: the file is broken, is no dump at all, or is gone
     */
    public function stage(Import $import, string $folder, Migrator $migrator, Budget $budget): bool
    {
        $reading = $import->reading ?? [];
        if (($reading['done'] ?? false) === true) {
            return true;
        }

        $dump = $folder . '/' . self::DUMP;
        if (!is_file($dump)) {
            $upload = $folder . '/' . self::UPLOAD;
            if (!is_file($upload)) {
                throw ImportRefusedException::on('file', ImportRefusedException::GONE);
            }
            $whole = BackupFile::unpack($upload, (string) ($reading['kind'] ?? ''), is_string($reading['entry'] ?? null) ? $reading['entry'] : null, $dump, $budget, $this->unpackedMost, $this->keepFree);
            if (!$whole) {
                // What the progress shows meanwhile: bytes unpacked, of a size not known yet.
                $import->reading = ['unpacked' => (int) filesize($dump . '.part')] + $reading;
                $import->save();

                return false;
            }
            Files::delete($upload);
        }
        $file = @fopen($dump, 'rb');
        if ($file === false) {
            throw ImportRefusedException::on('file', ImportRefusedException::GONE);
        }

        try {
            return $this->read($import, $file, (int) filesize($dump), $migrator, $budget);
        } finally {
            fclose($file);
        }
    }

    /**
     * Bytes of the dump read — inside the statement a step stopped in too —, of all it has; while it is unpacked, the
     * bytes unpacked so far, of a size not known yet.
     *
     * @return array{done: int, total: int|null}
     */
    public static function progress(Import $import): array
    {
        $reading = $import->reading ?? [];
        if (!is_int($reading['size'] ?? null)) {
            return ['done' => (int) ($reading['unpacked'] ?? 0), 'total' => null];
        }

        return ['done' => (int) ($reading['read'] ?? $reading['offset'] ?? 0), 'total' => $reading['size']];
    }

    /**
     * The reading itself. The budget is asked after each row staged and after each other statement: a step stops after
     * a row, saving the statement's offset and the rows of it staged, or between statements, saving the next one's.
     *
     * @param resource $file
     */
    private function read(Import $import, $file, int $size, Migrator $migrator, Budget $budget): bool
    {
        $reading = $import->reading ?? [];
        $schema = $import->schema ?? [];
        $data = ($reading['data'] ?? false) === true;
        $unmapped = array_values(array_filter((array) ($reading['unmapped'] ?? []), is_string(...)));
        $skip = (int) ($reading['skip'] ?? 0);
        $names = new Names($migrator->tables());
        $reader = new DumpReader($file, (int) ($reading['offset'] ?? 0), is_string($reading['delimiter'] ?? null) && $reading['delimiter'] !== '' ? $reading['delimiter'] : ';');
        $rows = new RowWriter($this->db, $this->encrypter, $import, $migrator->tables());
        // Where to go on from (`offset`, `skip`), and how far it read (`read`, past `offset` inside a long INSERT).
        $save = static function (int $offset, int $skip, bool $done) use (&$schema, &$data, &$unmapped, $reader, $rows, $size, $reading): void {
            $rows->flush(['offset' => $offset, 'skip' => $skip, 'read' => $reader->offset(), 'delimiter' => $reader->delimiter(), 'size' => $size, 'data' => $data, 'unmapped' => $unmapped, 'done' => $done] + $reading, $schema);
        };

        while (($statement = $reader->next()) !== null) {
            $data = true;
            $source = RowWriter::utf8($statement->table);
            $table = $names->table($source);
            $named = $table ?? $source;
            $columns = $statement->columns === null ? null : $names->columns($table, array_map(RowWriter::utf8(...), $statement->columns));

            if ($statement->kind === DumpStatement::CREATE) {
                $schema[$named] = $columns ?? [];
            } else {
                // An INSERT's columns are its own list's, or its table's CREATE TABLE's.
                if ($columns !== null) {
                    $schema[$named] ??= $columns;
                }
                $columns ??= $schema[$named] ?? null;
                if ($table !== null && $columns === null) {
                    if (!in_array($table, $unmapped, true)) {
                        $unmapped[] = $table;
                        $this->logger->warning('Import #{import}: rows of {table} passed over: its INSERT names no columns and no CREATE TABLE of it came before', ['import' => $import->id, 'table' => $table]);
                    }
                } elseif ($table !== null) {
                    $row = 0;
                    foreach ($reader->rows() as $values) {
                        if (++$row <= $skip) {
                            continue;
                        }
                        $rows->add($table, self::row($columns, $values));
                        if ($budget->spent()) {
                            $save($statement->start, $row, false);

                            return false;
                        }
                        if ($rows->full()) {
                            $save($statement->start, $row, false);
                        }
                    }
                }
                $reader->finish();
            }
            $skip = 0;

            if ($budget->spent()) {
                $save($reader->offset(), 0, false);

                return false;
            }
        }

        if (!$data) {
            throw ImportRefusedException::on('file', ImportRefusedException::NOT_A_DUMP);
        }
        $save($reader->offset(), 0, true);

        return true;
    }

    /**
     * A row's values under its columns: a value beyond them is dropped, a column beyond the values is null.
     *
     * @param list<string> $columns
     * @param list<?string> $values
     * @return array<string, ?string>
     */
    private static function row(array $columns, array $values): array
    {
        $row = [];
        foreach ($columns as $i => $column) {
            $row[$column] = $values[$i] ?? null;
        }

        return $row;
    }

    /** The name the file was uploaded under, without a path, as a column holds it; null for none. */
    private static function fileName(string $name): ?string
    {
        $name = trim(RowWriter::utf8(basename(str_replace('\\', '/', $name))));

        return $name === '' ? null : mb_substr($name, 0, 191);
    }

    /**
     * An upload's bytes to `$path`, a piece at a time — its stream copied, which every host's PHP and every test's request
     * can do —, readable by the app alone (it may hold panels' passwords).
     *
     * @throws ValidationException under `file`: the host has no room for it
     */
    private function copy(UploadedFileInterface $upload, string $path): void
    {
        $free = Files::freeSpace($path);
        if ($free !== null && $free - (int) $upload->getSize() < $this->keepFree) {
            throw ImportRefusedException::on('file', ImportRefusedException::NO_SPACE);
        }
        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException("The upload cannot be kept at {$path}.");
        }
        @chmod($path, Files::fileMode());
        try {
            while (!$stream->eof()) {
                $piece = $stream->read(1_048_576);
                if (@fwrite($out, $piece) !== strlen($piece)) {
                    throw ImportRefusedException::on('file', ImportRefusedException::NO_SPACE);
                }
            }
        } catch (ValidationException $e) {
            fclose($out);
            Files::delete($path);

            throw $e;
        }
        fclose($out);
    }
}
