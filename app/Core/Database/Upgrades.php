<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Connection;

/**
 * Runs database/upgrades/<version>.php files: each returns `static function (Builder $schema, Connection $db): void`
 * and brings a database of the release before up to that version. A shop runs, in version order, the upgrades newer
 * than its database (the version Installation records) and not newer than its code; the caller records the version
 * after each, so a failed upgrade is where the next try starts. A fresh install runs none.
 */
final class Upgrades
{
    /** An upgrade's file name: the version it brings the database to. */
    public const FILE = '/^(\d+\.\d+\.\d+)\.php$/';

    public function __construct(private readonly Connection $db) {}

    /**
     * The upgrade files in `$folder` newer than `$from` and not newer than `$to`, by version, in version order.
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
     * Run the pending upgrades in order; `$ran` is called with each version as soon as its upgrade is done.
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
