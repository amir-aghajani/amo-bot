<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

use App\Modules\Imports\Staging\Table;

/**
 * A source's tables and columns under the names the migrator gives them (Migrator::tables()): the same name, or one
 * that differs from it in case alone — MySQL on Windows keeps names in lower case, and a column's name has no case in
 * MySQL. What the migrator names is staged and described under its own spelling; everything else keeps the source's.
 */
final class Names
{
    /** @var array<string, string|null> The migrator's name of each source table asked about */
    private array $tableNames = [];

    /** @param array<string, Table> $tables */
    public function __construct(private readonly array $tables) {}

    /** The migrator's name for the source's `$table`; null when it reads no such table. */
    public function table(string $table): ?string
    {
        if (!array_key_exists($table, $this->tableNames)) {
            $this->tableNames[$table] = isset($this->tables[$table]) ? $table : self::match($table, array_keys($this->tables));
        }

        return $this->tableNames[$table];
    }

    /**
     * Columns of a source table, each under the migrator's spelling where it reads it; `$table` is the migrator's name,
     * or null for a table it does not read.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    public function columns(?string $table, array $columns): array
    {
        $read = $table === null ? null : ($this->tables[$table]->columns ?? null);
        if ($read === null) {
            return $columns;
        }

        return array_map(static fn(string $column): string => in_array($column, $read, true) ? $column : (self::match($column, $read) ?? $column), $columns);
    }

    /**
     * A source's tables and their columns, renamed so.
     *
     * @param array<array-key, list<string>> $schema
     * @return array<string, list<string>>
     */
    public function schema(array $schema): array
    {
        $named = [];
        foreach ($schema as $table => $columns) {
            $name = $this->table((string) $table);
            $named[$name ?? (string) $table] = $this->columns($name, $columns);
        }

        return $named;
    }

    /**
     * The one of `$names` that is `$name` but for case.
     *
     * @param list<string|int> $names
     */
    private static function match(string $name, array $names): ?string
    {
        $lower = mb_strtolower($name);
        foreach ($names as $candidate) {
            if (mb_strtolower((string) $candidate) === $lower) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
