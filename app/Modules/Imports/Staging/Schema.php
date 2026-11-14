<?php

declare(strict_types=1);

namespace App\Modules\Imports\Staging;

/**
 * The tables a source holds and each one's columns, in their order — as a backup file's CREATE TABLE statements (or
 * its INSERT statements' column lists) or the database itself say. What a migrator recognises a database by.
 */
final class Schema
{
    /** @param array<string, list<string>> $tables */
    public function __construct(private readonly array $tables) {}

    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /** Whether `$table` is there with every one of `$columns`. */
    public function hasColumns(string $table, string ...$columns): bool
    {
        return isset($this->tables[$table]) && array_diff($columns, $this->tables[$table]) === [];
    }

    /** @return list<string> Its columns in order; none for a table it does not hold */
    public function columns(string $table): array
    {
        return $this->tables[$table] ?? [];
    }

    /** @return array<string, list<string>> */
    public function tables(): array
    {
        return $this->tables;
    }
}
