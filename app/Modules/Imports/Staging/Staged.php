<?php

declare(strict_types=1);

namespace App\Modules\Imports\Staging;

/**
 * The staged copy of another bot's database, as a migrator reads it: each table's rows in the order they were read,
 * each under its `seq` — unique and increasing within the import —, as the source gave them: every value a string or
 * null (a number is its digits, a date the source's own text), a secret already decrypted. A table the source did not
 * have has no rows.
 */
interface Staged
{
    /**
     * The rows of `$table` after `$after`, in order, read a chunk at a time.
     *
     * @return iterable<int, array<string, ?string>> keyed by seq
     */
    public function rows(string $table, int $after = 0): iterable;

    /** How many rows `$table` has. */
    public function count(string $table): int;

    /**
     * Every row of a small table at once (a bot's panels, plans, categories, settings) — never of its customers or
     * their services.
     *
     * @return list<array<string, ?string>>
     */
    public function all(string $table): array;
}
