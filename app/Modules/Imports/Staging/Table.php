<?php

declare(strict_types=1);

namespace App\Modules\Imports\Staging;

/**
 * A table a migrator reads (Migrator::tables()): the columns staged of it — null for all — and those that are secrets
 * (staged encrypted with APP_KEY, read back plain). A column the database lacks is staged as null; whether a database
 * lacking a table is that bot's at all is the migrator's to say (Migrator::recognize()).
 */
final class Table
{
    /**
     * @param list<string>|null $columns
     * @param list<string> $secrets
     */
    public function __construct(
        public readonly ?array $columns = null,
        public readonly array $secrets = [],
    ) {}
}
