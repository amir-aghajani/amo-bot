<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Staging\Table;
use Illuminate\Database\Connection;

/**
 * Stages rows into `import_rows` a batch at a time, each batch written together with where the reading got to — in one
 * transaction, so a reading stopped anywhere (its time up, the process killed) has staged every row once. A row is
 * kept as the migrator reads it: its columns (all the source's when it names none), a missing one null, every value
 * valid UTF-8, its secrets encrypted with APP_KEY.
 */
final class RowWriter
{
    /** The rows one insert writes: three values each, within any driver's limit for one statement (SQLite's oldest, 999). */
    public const BATCH = 300;

    /**
     * The staged bytes past which a batch is full however few its rows: large rows never outgrow a host's memory_limit,
     * nor one insert MySQL's max_allowed_packet.
     */
    public const BATCH_BYTES = 4 * 1024 * 1024;

    /** Where the import's reading keeps how many rows it has staged in all. */
    public const STAGED = 'staged';

    /** @var list<array{import_id: int, table_name: string, data: string}> */
    private array $pending = [];

    /** The bytes of the rows waiting to be flushed. */
    private int $bytes = 0;

    /** @param array<string, Table> $tables */
    public function __construct(
        private readonly Connection $db,
        private readonly Encrypter $encrypter,
        private readonly Import $import,
        private readonly array $tables,
    ) {}

    /** Text as valid UTF-8: what is not is replaced, never refused. */
    public static function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    /**
     * A row of the migrator's `$table`, staged with the next flush().
     *
     * @param array<string, ?string> $row By the migrator's names of the columns (Names)
     */
    public function add(string $table, array $row): void
    {
        $read = $this->tables[$table] ?? new Table();
        $data = [];
        foreach ($read->columns ?? array_keys($row) as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null) {
                $value = self::utf8($value);
                if (in_array($column, $read->secrets, true)) {
                    $value = $this->encrypter->encrypt($value);
                }
            }
            $data[self::utf8((string) $column)] = $value;
        }
        $json = json_encode($data, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->pending[] = ['import_id' => $this->import->id, 'table_name' => $table, 'data' => $json];
        $this->bytes += strlen($json);
    }

    /** Whether a batch is waiting to be flushed: BATCH rows, or BATCH_BYTES of them. */
    public function full(): bool
    {
        return count($this->pending) >= self::BATCH || $this->bytes >= self::BATCH_BYTES;
    }

    /**
     * The rows added since the last flush, staged, with the import's reading — and the schema, when given — saved in the
     * same transaction. The reading keeps how many rows are staged in all (STAGED), so a staged copy that lost rows since
     * (a restore empties it) is told before the reading goes on as though it had them (Imports).
     *
     * @param array<string, mixed> $reading
     * @param array<string, list<string>>|null $schema
     */
    public function flush(array $reading, ?array $schema = null): void
    {
        $reading[self::STAGED] = (int) ($this->import->reading[self::STAGED] ?? 0) + count($this->pending);
        $this->db->transaction(function () use ($reading, $schema): void {
            foreach (array_chunk($this->pending, self::BATCH) as $batch) {
                $this->db->table('import_rows')->insert($batch);
            }
            $this->import->reading = $reading;
            if ($schema !== null) {
                $this->import->schema = $schema;
            }
            $this->import->save();
        });
        $this->pending = [];
        $this->bytes = 0;
    }
}
