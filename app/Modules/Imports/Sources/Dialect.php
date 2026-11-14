<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

/**
 * How DatabaseSource speaks to the other bot's database: MySQL's way (MySqlDialect) — the only kind of database such a
 * bot runs on —; the tests stand a SQLite file in for one through their own.
 */
interface Dialect
{
    /**
     * A connection to the database, reading every value as text, read-only where the server can be told so.
     *
     * @param array{host: string, port: int, database: string, username: string, password: string} $connection
     * @throws \PDOException
     */
    public function connect(array $connection): \PDO;

    /**
     * The database's tables, each with its columns in order and those of its primary key.
     *
     * @return array<string, array{columns: list<string>, key: list<string>}>
     * @throws \PDOException
     */
    public function tables(\PDO $pdo, string $database): array;

    /** A table's or a column's name, quoted for a statement. */
    public function quote(string $name): string;
}
