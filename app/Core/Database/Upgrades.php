<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Connection;

/**
 * The database's way from one release to the next. Before the first release there were no migrations — database/
 * schema.php was the database, and `db:rebuild` followed it —; from it on, a release that changes database/schema.php
 * ships the same change as an upgrade: database/upgrades/<version>.php, returning
 * `static function (Builder $schema, Connection $db): void`, which brings a database of the release before it up to it.
 * A shop runs, in version order, the upgrades newer than the version its database is at (the installation's lock,
 * Core\Installation) and not newer than its code's; the version moves after each, so an upgrade that fails is where the
 * next try starts. A fresh install makes schema.php's tables as they stand and records its code's version: it has no
 * upgrade to run.
 */
final class Upgrades
{
    /** An upgrade's file name: the version it brings the database to. */
    public const FILE = '/^(\d+\.\d+\.\d+)\.php$/';

    public function __construct(private readonly Connection $db) {}

    /**
     * The upgrades in `$folder` newer than `$from` and not newer than `$to`, in version order: each file by its version.
     *
     * @return array<string, string>
     * @throws \UnexpectedValueException for a PHP file there that no version names: an upgrade that would never run
     */
    public function pending(string $folder, string $from, string $to): array
    {
        $pending = [];
        foreach (glob($folder . '/*.php') ?: [] as $file) {
            if (preg_match(self::FILE, basename($file), $name) !== 1) {
                throw new \UnexpectedValueException(basename($file) . ' is in database/upgrades, but no version names it.');
            }
            if (version_compare($name[1], $from, '>') && version_compare($name[1], $to, '<=')) {
                $pending[$name[1]] = $file;
            }
        }
        uksort($pending, version_compare(...));

        return $pending;
    }

    /**
     * Run the pending upgrades in order, each on the schema builder of the shop's connection; `$ran` hears each version
     * as soon as its upgrade is done — where the database is now.
     *
     * @param \Closure(string): void $ran
     * @throws UpgradeFailedException naming the upgrade that failed: the ones before it are done
     */
    public function run(string $folder, string $from, string $to, \Closure $ran): void
    {
        foreach ($this->pending($folder, $from, $to) as $version => $file) {
            try {
                $upgrade = require $file;
                if (!$upgrade instanceof \Closure) {
                    throw new \UnexpectedValueException(basename($file) . ' returns no upgrade.');
                }
                $upgrade($this->db->getSchemaBuilder(), $this->db);
            } catch (\Throwable $e) {
                throw new UpgradeFailedException($version, $e);
            }
            $ran($version);
        }
    }
}
