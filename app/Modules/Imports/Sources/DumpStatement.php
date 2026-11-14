<?php

declare(strict_types=1);

namespace App\Modules\Imports\Sources;

/**
 * A statement of a dump that carries data (DumpReader): a table's columns (CREATE TABLE), or rows of it to come (INSERT
 * or REPLACE), their columns null when the statement names none. `start` is where it begins in the file: a reading
 * that stops inside its rows goes on from there.
 */
final class DumpStatement
{
    public const CREATE = 'create';
    public const INSERT = 'insert';

    /** @param list<string>|null $columns */
    public function __construct(
        public readonly string $kind,
        public readonly int $start,
        public readonly string $table,
        public readonly ?array $columns,
    ) {}
}
