<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

use App\Core\Drivers\Driver;
use Illuminate\Database\Schema\Builder;

/**
 * A database the shop can run on (MySQL, SQLite, …): everything the rest of the application asks about one, so a new
 * database is one class registered in bootstrap/container.php (`database.drivers`) — the installer, the settings
 * screen, the requirements, the dashboard and the schema tooling ask a driver and name no database
 * (DatabaseManager::driver() is the one the shop runs on, the one DB_CONNECTION names).
 *
 * Its key is what DB_CONNECTION holds; its form (describe()) is its connection settings — each field kept under its
 * config.php setting (DB_HOST…), a password a secret that stays with the address it was given for. `$config` is always
 * a set of config.php settings (key => value): the file the shop booted with, the file the installer is writing, or
 * what the admin's form just made — the driver reads its own through its form, a key that is missing as its field's
 * default.
 */
interface DatabaseDriver extends Driver
{
    /** The trait (Descriptor::$traits) that says whether the installer offers it; the settings screen also shows the one the shop runs on. */
    public const INSTALLABLE = 'installable';

    /** @return list<string> The PHP extensions it needs (Requirements lists them; a probe without one says so). */
    public function extensions(): array;

    /**
     * The illuminate/database connection for these settings — charset, a UTC session, strict mode, PDO options: the one
     * description of it, whether the shop runs on it or a probe tries it.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function connection(array $config): array;

    /**
     * Connect with these settings, briefly, and say what answered — or why the shop cannot run on it, in the admin's
     * words: nothing is written to config.php before this says yes. The caller has checked extensions().
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
     * Before a rebuild renames `$table` aside (Schema::rebuild()): drop the names the table holds that are the
     * database's rather than the table's, which its new version is about to take.
     */
    public function releaseNames(Builder $schema, string $table): void;
}
