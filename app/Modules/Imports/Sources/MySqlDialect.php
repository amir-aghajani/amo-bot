<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

/**
 * The other bot's MySQL (or MariaDB) database: utf8mb4, a few seconds to connect and a limit on each statement, every
 * value read as text, the session read-only where the server can say so (MySQL 5.6.5 and later, MariaDB 10.0 and
 * later), its tables from information_schema.
 */
final class MySqlDialect implements Dialect
{
    /** How long a connection may take to open, seconds. */
    private const TIMEOUT = 5;

    /** How long one statement may run on the other server, seconds: within a step, which is a request's few seconds. */
    private const STATEMENT_SECONDS = 20;

    public function connect(array $connection): \PDO
    {
        $pdo = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $connection['host'], $connection['port'], $connection['database']),
            $connection['username'],
            $connection['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => self::TIMEOUT, \PDO::ATTR_STRINGIFY_FETCHES => true],
        );
        // Each told on its own: a server that knows none of them (an older one) or not the other's (MySQL's is in
        // milliseconds, MariaDB's in seconds) refuses it, and nothing here writes anyway.
        foreach (['SET SESSION TRANSACTION READ ONLY', 'SET SESSION max_execution_time = ' . self::STATEMENT_SECONDS * 1000, 'SET SESSION max_statement_time = ' . self::STATEMENT_SECONDS] as $setting) {
            try {
                $pdo->exec($setting);
            } catch (\PDOException) {
            }
        }

        return $pdo;
    }

    public function tables(\PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION');
        if ($statement === false || !$statement->execute([$database])) {
            throw new \PDOException('The tables of the database could not be read.');
        }
        $tables = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $line) {
            [$table, $column, $key] = array_map(strval(...), $line);
            $tables[$table] ??= ['columns' => [], 'key' => []];
            $tables[$table]['columns'][] = $column;
            if ($key === 'PRI') {
                $tables[$table]['key'][] = $column;
            }
        }

        return $tables;
    }

    public function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
