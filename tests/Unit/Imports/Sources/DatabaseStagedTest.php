<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Staging\DatabaseStaged;
use App\Modules\Imports\Staging\Table;

/**
 * The staged copy as a migrator reads it: a table's rows of its own import alone, in the order they were staged, each
 * under its seq, after a seq, across chunks; counted; all at once; the secrets decrypted — one that no longer decrypts
 * read as null.
 */
final class DatabaseStagedTest extends StagingTestCase
{
    public function testReadsATablesRowsInOrderAfterASeq(): void
    {
        $import = $this->import();
        $other = $this->import();
        for ($i = 1; $i <= 2500; $i++) {
            $this->stage($import->id, 'user', ['id' => (string) $i, 'username' => "u{$i}"]);
            if ($i % 500 === 0) {
                $this->stage($import->id, 'invoice', ['id_invoice' => (string) $i]);
                $this->stage($other->id, 'user', ['id' => 'other']);
            }
        }
        $staged = $this->reader($import->id);

        $rows = iterator_to_array($staged->rows('user'));
        self::assertCount(2500, $rows);
        self::assertSame(range(1, 2500), array_map(static fn(array $row): int => (int) $row['id'], array_values($rows)));
        $seqs = array_keys($rows);
        $increasing = array_values(array_unique($seqs));
        sort($increasing);
        self::assertSame($increasing, $seqs);

        $after = iterator_to_array($staged->rows('user', $seqs[1999]));
        self::assertSame(array_slice($seqs, 2000), array_keys($after));
        self::assertSame('2001', reset($after)['id'] ?? null);

        self::assertSame(2500, $staged->count('user'));
        self::assertSame(5, $staged->count('invoice'));
        self::assertSame(0, $staged->count('Payment_report'));
        self::assertSame([], iterator_to_array($staged->rows('Payment_report')));
        self::assertSame(['id_invoice' => '500'], $staged->all('invoice')[0]);
    }

    public function testDecryptsTheSecretsOfTheMigratorsTables(): void
    {
        $import = $this->import();
        $encrypter = $this->service(Encrypter::class);
        $this->stage($import->id, 'marzban_panel', ['name_panel' => 'DE', 'password_panel' => $encrypter->encrypt('pa;ss'), 'api_key' => 'not encrypted with this key']);
        $this->stage($import->id, 'marzban_panel', ['name_panel' => 'NL', 'password_panel' => null, 'api_key' => $encrypter->encrypt('')]);

        self::assertSame([
            ['name_panel' => 'DE', 'password_panel' => 'pa;ss', 'api_key' => null],
            ['name_panel' => 'NL', 'password_panel' => null, 'api_key' => ''],
        ], $this->reader($import->id)->all('marzban_panel'));
    }

    public function testIsWhatTheStagerHandsTheMigrator(): void
    {
        $import = $this->stagedBackup(self::fixture('faoxima-php.sql'));

        $staged = $this->stager()->staged($import, TablesMigrator::bot());

        self::assertInstanceOf(DatabaseStaged::class, $staged);
        self::assertSame(3, $staged->count('user'));
    }

    private function reader(int $import): DatabaseStaged
    {
        return new DatabaseStaged($this->db(), $this->service(Encrypter::class), $import, [
            'user' => new Table(['id', 'username']),
            'marzban_panel' => new Table(['name_panel', 'password_panel', 'api_key'], ['password_panel', 'api_key']),
        ]);
    }

    /** @param array<string, ?string> $data */
    private function stage(int $import, string $table, array $data): void
    {
        $this->db()->table('import_rows')->insert(['import_id' => $import, 'table_name' => $table, 'data' => json_encode($data, JSON_THROW_ON_ERROR)]);
    }
}
