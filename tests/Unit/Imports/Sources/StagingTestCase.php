<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Sources\BackupFile;
use App\Modules\Imports\Sources\Stager;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\DatabaseTestCase;

/**
 * What the sources' tests share: a Stager over a folder of the test's own (storage/imports) and a SQLite file standing
 * in for the other bot's database, imports to stage into, uploads as the panels send them, and the staged copy read back.
 */
abstract class StagingTestCase extends DatabaseTestCase
{
    protected string $folder;

    protected SqliteDialect $dialect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder = $this->scratchDir();
        $this->dialect = new SqliteDialect();
    }

    /** The Stager, unpacking at most `$unpackedMost` and leaving `$keepFree` free on the disk. */
    protected function stager(int $unpackedMost = BackupFile::MOST, int $keepFree = BackupFile::KEEP_FREE): Stager
    {
        return new Stager($this->db(), $this->service(Encrypter::class), $this->service(LoggerInterface::class), $this->folder, $this->dialect, $unpackedMost, $keepFree);
    }

    protected function import(ImportSource $source = ImportSource::Backup): Import
    {
        return Import::query()->create(['bot_id' => 1, 'migrator' => 'tables', 'source' => $source, 'phase' => ImportPhase::Reading]);
    }

    /** An upload of `$bytes` under `$name`, as the panels send it. */
    protected static function upload(string $bytes, string $name = 'backup.sql', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($bytes), $name, null, strlen($bytes), $error);
    }

    protected static function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/Fixtures/imports/dumps/' . $name);
    }

    /** A backup of `$bytes` uploaded for a new import and staged whole. */
    protected function stagedBackup(string $bytes, string $name = 'backup.sql', ?TablesMigrator $migrator = null): Import
    {
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload($bytes, $name));
        self::assertTrue($this->stager()->stage($import, $migrator ?? TablesMigrator::bot(), Budget::unlimited()));

        return $import;
    }

    /**
     * Stage `$import` a step at a time, each step on the import as the database holds it (as the next request reads
     * it); returns how many steps it took.
     */
    protected function stageInSteps(Import $import, int $checks, ?TablesMigrator $migrator = null, int $unpackedMost = BackupFile::MOST): int
    {
        $steps = 0;
        do {
            $import = Import::query()->findOrFail($import->id);
            $steps++;
            self::assertLessThan(10_000, $steps, 'The reading goes on for ever.');
        } while (!$this->stager($unpackedMost)->stage($import, $migrator ?? TablesMigrator::bot(), Budget::checks($checks)));

        return $steps;
    }

    /**
     * The staged copy as the migrator reads it — every table it reads, with rows —, the rows without their seqs.
     *
     * @return array<string, list<array<string, ?string>>>
     */
    protected function staged(Import $import, ?TablesMigrator $migrator = null): array
    {
        $migrator ??= TablesMigrator::bot();
        $staged = $this->stager()->staged(Import::query()->findOrFail($import->id), $migrator);
        $tables = [];
        foreach (array_keys($migrator->tables()) as $table) {
            $rows = $staged->all($table);
            if ($rows !== []) {
                $tables[$table] = $rows;
            }
        }

        return $tables;
    }

    /**
     * The import's staged rows as they rest in the database: each table's rows, as their JSON decodes.
     *
     * @return array<string, list<array<string, ?string>>>
     */
    protected function atRest(Import $import): array
    {
        $tables = [];
        foreach ($this->db()->table('import_rows')->where('import_id', $import->id)->orderBy('id')->get() as $row) {
            $tables[(string) $row->table_name][] = (array) json_decode((string) $row->data, true);
        }

        return $tables;
    }
}
