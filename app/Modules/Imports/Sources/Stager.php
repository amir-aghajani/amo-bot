<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Core\Exceptions\ValidationException;
use App\Core\Security\Encrypter;
use App\Core\Support\Files;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Contracts\Migrator;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Staging\DatabaseStaged;
use App\Modules\Imports\Staging\Schema;
use App\Modules\Imports\Staging\Staged;
use Illuminate\Database\Connection;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the other bot's data into an import's staged copy (`import_rows`) from either source the owner gives: its
 * backup file (DumpSource) or a read-only connection to its database (DatabaseSource) — only the tables and columns
 * the migrator reads, their secrets encrypted with APP_KEY —, a step at a time. An import's files are kept in a folder
 * of its own under storage/imports (never served), gone with its connection once the reading is done, and its staged
 * rows once it ends.
 */
final class Stager
{
    /** The staged rows one delete removes when an import is discarded: a large copy goes in short statements. */
    private const DISCARD_ROWS = 5000;

    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly LoggerInterface $logger,
        /** storage/imports: never served as it is */
        private readonly string $folder,
        /** How the other bot's database is spoken to — MySQL; a test stands a SQLite file in for it */
        private readonly Dialect $dialect = new MySqlDialect(),
        /** The most a backup may unpack to, bytes (a test sets less) */
        private readonly int $unpackedMost = BackupFile::MOST,
        /** The free space keeping and unpacking a backup leave on the disk, bytes (a test sets more than there is) */
        private readonly int $keepFree = BackupFile::KEEP_FREE,
    ) {}

    /**
     * Keep a backup file uploaded for `$import` (saved already) in storage/imports/<id>/, checked by its bytes to be a
     * .sql, a .zip holding one or a .gz of one; `file_name` and the reading are saved on the import.
     *
     * @throws ValidationException under `file`
     */
    public function keepUpload(Import $import, ?UploadedFileInterface $file): void
    {
        $folder = $this->folder($import);
        try {
            $this->dumps()->keep($import, $folder, $file);
        } catch (ValidationException $e) {
            self::remove($folder);

            throw $e;
        }
    }

    /**
     * Try a database connection ({host, port, database, username, password}) at once and keep it on the import (saved
     * already), encrypted (`connection`), with the database's tables (`schema`).
     *
     * @param array<string, mixed> $connection
     * @throws ValidationException under the field at fault
     */
    public function keepConnection(Import $import, array $connection): void
    {
        $this->database()->keep($import, $connection);
    }

    /**
     * Read on from where the import's reading stopped, staging every table the migrator names, until done or `$budget`
     * is spent. True once everything is staged and the schema is known.
     *
     * @throws ValidationException under `file` / `connection` when it cannot be read on
     */
    public function stage(Import $import, Migrator $migrator, Budget $budget): bool
    {
        return $import->source === ImportSource::Backup
            ? $this->dumps()->stage($import, $this->folder($import), $migrator, $budget)
            : $this->database()->stage($import, $migrator, $budget);
    }

    /** The source's tables and columns, once stage() returned true (a database's from the start). */
    public function schema(Import $import): Schema
    {
        return new Schema($import->schema ?? []);
    }

    /**
     * How far the reading is: bytes of the dump read, or rows of the database staged — of how many (null until known).
     *
     * @return array{done: int, total: int|null}
     */
    public function progress(Import $import): array
    {
        return $import->source === ImportSource::Backup ? DumpSource::progress($import) : DatabaseSource::progress($import);
    }

    /** The staged copy as the migrator reads it. */
    public function staged(Import $import, Migrator $migrator): Staged
    {
        return new DatabaseStaged($this->db, $this->encrypter, $import->id, $migrator->tables());
    }

    /**
     * Once the reading is done: the import's folder (the backup file, its dump) and its kept connection gone — they may
     * hold the other bot's passwords —, the staged rows kept for the rest of the import.
     */
    public function readingDone(Import $import): void
    {
        self::remove($this->folder . '/' . $import->id);
        if ($import->connection !== null) {
            $import->connection = null;
            $import->save();
        }
    }

    /** The folder of an import whose record is gone (a restore took it away), and what it kept of the other bot. */
    public function discardFolder(int $import): void
    {
        self::remove($this->folder . '/' . $import);
    }

    /** The import's folder, staged rows and kept connection, gone. */
    public function discard(Import $import): void
    {
        $this->readingDone($import);
        do {
            // The last row of the next few thousand: a delete of a range, as every database can run one.
            $last = $this->db->table('import_rows')->where('import_id', $import->id)->orderBy('id')->offset(self::DISCARD_ROWS - 1)->limit(1)->value('id');
            $rows = $this->db->table('import_rows')->where('import_id', $import->id);
            if ($last !== null) {
                $rows->where('id', '<=', $last);
            }
            $rows->delete();
        } while ($last !== null);
    }

    private function dumps(): DumpSource
    {
        return new DumpSource($this->db, $this->encrypter, $this->logger, $this->unpackedMost, $this->keepFree);
    }

    private function database(): DatabaseSource
    {
        return new DatabaseSource($this->db, $this->encrypter, $this->dialect);
    }

    /** The import's own folder, made when missing — the app's alone (it may hold panels' passwords). */
    private function folder(Import $import): string
    {
        $folder = $this->folder . '/' . $import->id;
        if (!is_dir($folder) && !@mkdir($folder, Files::folderMode(), true) && !is_dir($folder)) {
            throw new \RuntimeException("The folder {$folder} cannot be made.");
        }

        return $folder;
    }

    /** A folder and the files in it (an import's folder holds no other folder), gone. */
    private static function remove(string $folder): void
    {
        foreach (glob($folder . '/*') ?: [] as $file) {
            Files::delete($file);
        }
        if (is_dir($folder)) {
            @rmdir($folder);
        }
    }
}
