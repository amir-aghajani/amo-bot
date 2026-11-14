<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Modules\Imports\Sources\Dialect;

/**
 * A SQLite file standing in for the other bot's MySQL database: the connection's `database` is the file's path, the
 * rest is ignored. `$refusal`, when set, is what connecting throws — a MySQL refusal, as PDO words it.
 */
final class SqliteDialect implements Dialect
{
    public ?\PDOException $refusal = null;

    /** @var list<array<string, int|string>> The connections asked for */
    public array $connections = [];

    public function connect(array $connection): \PDO
    {
        $this->connections[] = $connection;
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
        if (!is_file($connection['database'])) {
            throw new \PDOException("SQLSTATE[HY000] [1049] Unknown database '{$connection['database']}'", 1049);
        }

        return new \PDO('sqlite:' . $connection['database'], null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_STRINGIFY_FETCHES => true]);
    }

    public function tables(\PDO $pdo, string $database): array
    {
        $tables = [];
        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        foreach ($names === false ? [] : $names->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;
            $tables[$table] = ['columns' => [], 'key' => []];
            $columns = $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')');
            foreach ($columns === false ? [] : $columns->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                $tables[$table]['columns'][] = (string) $column['name'];
                if ((int) $column['pk'] > 0) {
                    $tables[$table]['key'][] = (string) $column['name'];
                }
            }
        }

        return $tables;
    }

    public function quote(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
