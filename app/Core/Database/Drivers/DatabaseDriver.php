<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

use App\Core\Drivers\Driver;
use Illuminate\Database\Schema\Builder;

/**
 * A database the shop can run on: everything the app asks about one, so no other code names a database. Its key is
 * what DB_CONNECTION holds; its form is its connection settings, each field kept under its config.php setting. Every
 * `$config` is a set of config.php settings, read through the form (a missing one is its field's default).
 */
interface DatabaseDriver extends Driver
{
    /** The trait saying whether the installer offers it. */
    public const INSTALLABLE = 'installable';

    /** @return list<string> The PHP extensions it needs (Requirements lists them; a probe without one says so). */
    public function extensions(): array;

    /**
     * The illuminate/database connection for these settings (charset, a UTC session, strict mode, PDO options) — the
     * same for the shop and for a probe.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function connection(array $config): array;

    /**
     * Connect briefly with these settings and report what answered, or refuse with a reason for the admin (too old, wrong
     * password, …). Nothing is written to config.php before a probe passes. The caller has checked extensions().
     *
     * @param array<string, mixed> $config
     * @throws ProbeFailedException
     */
    public function probe(array $config): ProbeResult;

    /**
     * SQL for the date (Y-m-d) a UTC datetime column falls on `$offset` seconds east of UTC — the dashboard's day.
     *
     * @param string $column Already wrapped by the query's grammar
     */
    public function localDate(string $column, int $offset): string;

    /**
     * Before Schema::rebuild() renames `$table` aside: drop the key names that are unique across the database rather
     * than the table (MySQL's foreign keys, SQLite's indexes), which the new table is about to take.
     */
    public function releaseNames(Builder $schema, string $table): void;
}
