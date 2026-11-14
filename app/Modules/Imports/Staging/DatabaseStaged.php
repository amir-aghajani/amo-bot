<?php

declare(strict_types=1);

namespace App\Modules\Imports\Staging;

use App\Core\Security\DecryptionException;
use App\Core\Security\Encrypter;
use Illuminate\Database\Connection;

/**
 * The staged copy of an import as its migrator reads it: the import's rows of `import_rows`, a table's in the order they
 * were staged, a chunk at a time, each under its id as its seq; the secrets of the migrator's tables decrypted (one that
 * no longer decrypts — APP_KEY changed since — reads as null).
 */
final class DatabaseStaged implements Staged
{
    /** The rows one query reads. */
    private const CHUNK = 1000;

    /** @param array<string, Table> $tables The migrator's tables (Migrator::tables()) */
    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly int $import,
        private readonly array $tables,
    ) {}

    public function rows(string $table, int $after = 0): iterable
    {
        $secrets = $this->tables[$table]->secrets ?? [];
        do {
            $chunk = $this->db->table('import_rows')
                ->where('import_id', $this->import)
                ->where('table_name', $table)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'data']);
            foreach ($chunk as $line) {
                $after = (int) $line->id;

                yield $after => $this->row((string) $line->data, $secrets);
            }
        } while ($chunk->count() === self::CHUNK);
    }

    public function count(string $table): int
    {
        return $this->db->table('import_rows')->where('import_id', $this->import)->where('table_name', $table)->count();
    }

    public function all(string $table): array
    {
        return array_values(iterator_to_array($this->rows($table)));
    }

    /**
     * A staged row, its secrets decrypted.
     *
     * @param list<string> $secrets
     * @return array<string, ?string>
     */
    private function row(string $data, array $secrets): array
    {
        $row = [];
        foreach ((array) json_decode($data, true) as $column => $value) {
            $value = is_scalar($value) ? (string) $value : null;
            if ($value !== null && in_array((string) $column, $secrets, true)) {
                try {
                    $value = $this->encrypter->decrypt($value);
                } catch (DecryptionException) {
                    $value = null;
                }
            }
            $row[(string) $column] = $value;
        }

        return $row;
    }
}
