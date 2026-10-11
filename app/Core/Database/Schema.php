<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Database\Drivers\DatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The tables database/schema.php describes. create() makes the ones a database lacks (the installer, the tests);
 * rebuild() (`db:rebuild`) makes every table again after an edit of the schema, carrying its rows over. An installed
 * shop follows a new release through database/upgrades/ instead (Upgrades).
 */
final class Schema
{
    /** What a table is called while a rebuild reads its rows into the new one. */
    public const ASIDE = '__old';

    /**
     * @param DatabaseDriver $driver The driver `$db` was made by
     * @param array{tables: array<string, \Closure(Blueprint): void>, rows?: array<string, \Closure(): list<array<string, mixed>>>} $definition
     */
    public function __construct(
        private readonly Connection $db,
        private readonly DatabaseDriver $driver,
        private readonly array $definition,
    ) {}

    /** @return list<string> Every table, in the order they are made. */
    public function tables(): array
    {
        return array_keys($this->definition['tables']);
    }

    /** @return list<string> The tables this database does not have. */
    public function missing(): array
    {
        $schema = $this->db->getSchemaBuilder();

        return array_values(array_filter($this->tables(), static fn(string $table) => !$schema->hasTable($table)));
    }

    /**
     * Make the tables this database does not have, in order, each with the rows a new shop starts with.
     *
     * @return list<string> The tables made
     */
    public function create(): array
    {
        $missing = $this->missing();
        foreach ($missing as $table) {
            $this->make($table, withRows: true);
        }

        return $missing;
    }

    /**
     * Make every table again as the schema says now and carry its rows over by column name: a shared column keeps its
     * values, a new one takes its default (a NOT NULL one needs one), a dropped one goes. A row the new shape will not
     * take stops the rebuild with the database's error; the old rows wait in `<table>__old`, and the next rebuild, after
     * the schema is fixed, picks up from there. Returns, per table, its rows now (null: new to this database) and the
     * columns it gained and lost.
     *
     * @return list<array{table: string, rows: int|null, added: list<string>, dropped: list<string>}>
     */
    public function rebuild(): array
    {
        $schema = $this->db->getSchemaBuilder();

        $schema->withoutForeignKeyConstraints(function () use ($schema): void {
            foreach ($this->tables() as $table) {
                if (!$schema->hasTable($table . self::ASIDE)) {
                    if ($schema->hasTable($table)) {
                        $this->moveAside($table);
                    }
                } else {
                    // From a rebuild that stopped: the old rows stay aside, and what it made beside them is made again
                    // below, as the schema says now — that may be what stopped it.
                    $schema->dropIfExists($table);
                }
            }
        });

        // With the checks on: MariaDB records a foreign key added while they are off differently (RESTRICT as NO ACTION).
        foreach ($this->tables() as $table) {
            $this->make($table, withRows: !$schema->hasTable($table . self::ASIDE));
        }

        return $schema->withoutForeignKeyConstraints(function () use ($schema): array {
            $report = array_map($this->carryOver(...), $this->tables());
            foreach ($this->tables() as $table) {
                $schema->dropIfExists($table . self::ASIDE);
            }

            return $report;
        });
    }

    private function make(string $table, bool $withRows): void
    {
        $this->db->getSchemaBuilder()->create($table, $this->definition['tables'][$table]);

        $rows = $this->definition['rows'][$table] ?? null;
        if ($withRows && $rows !== null) {
            $this->db->table($table)->insert($rows());
        }
    }

    /**
     * Rename the table to `<table>__old`. Some of its key names belong to the database rather than the table (MySQL's
     * foreign keys, SQLite's indexes), so they are dropped first for the new table to take; the old rows are only read
     * from now on.
     */
    private function moveAside(string $table): void
    {
        $schema = $this->db->getSchemaBuilder();
        $this->driver->releaseNames($schema, $table);
        $schema->rename($table, $table . self::ASIDE);
    }

    /**
     * Copy the old rows into the new table, emptied first: a copy from a rebuild that stopped, or a row the bot wrote
     * meanwhile, gives way to them.
     *
     * @return array{table: string, rows: int|null, added: list<string>, dropped: list<string>}
     */
    private function carryOver(string $table): array
    {
        $schema = $this->db->getSchemaBuilder();
        $aside = $table . self::ASIDE;
        $columns = $schema->getColumnListing($table);
        if (!$schema->hasTable($aside)) {
            return ['table' => $table, 'rows' => null, 'added' => [], 'dropped' => []];
        }

        $old = $schema->getColumnListing($aside);
        // A generated column is the database's to compute (grant_parts.running_server_id): never copied.
        $generated = array_column(array_filter($schema->getColumns($table), static fn(array $column): bool => ($column['generation'] ?? null) !== null), 'name');
        $shared = array_values(array_diff(array_intersect($columns, $old), $generated));
        $this->db->table($table)->delete();
        $this->db->table($table)->insertUsing($shared, $this->db->table($aside)->select($shared));

        $before = $this->db->table($aside)->count();
        $after = $this->db->table($table)->count();
        if ($after !== $before) {
            throw new \RuntimeException("{$table} took {$after} of its {$before} rows; they wait in {$aside}.");
        }

        return [
            'table' => $table,
            'rows' => $after,
            'added' => array_values(array_diff($columns, $old)),
            'dropped' => array_values(array_diff($old, $columns)),
        ];
    }
}
