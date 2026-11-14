<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Modules\Imports\Staging\Staged;

/**
 * A staged copy held in memory, for a migrator's tests: tables of rows as a source would stage them (every value a
 * string or null), each row's seq its place across the whole copy, counted from 1 in the order the tables are given.
 */
final class ArrayStaged implements Staged
{
    /** @var array<string, array<int, array<string, ?string>>> */
    private array $tables = [];

    /** @param array<string, list<array<string, string|int|null>>> $tables */
    public function __construct(array $tables)
    {
        $seq = 0;
        foreach ($tables as $name => $rows) {
            $this->tables[$name] = [];
            foreach ($rows as $row) {
                $this->tables[$name][++$seq] = array_map(static fn(string|int|null $value): ?string => $value === null ? null : (string) $value, $row);
            }
        }
    }

    public function rows(string $table, int $after = 0): iterable
    {
        foreach ($this->tables[$table] ?? [] as $seq => $row) {
            if ($seq > $after) {
                yield $seq => $row;
            }
        }
    }

    public function count(string $table): int
    {
        return count($this->tables[$table] ?? []);
    }

    public function all(string $table): array
    {
        return array_values($this->tables[$table] ?? []);
    }
}
