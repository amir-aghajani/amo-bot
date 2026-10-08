<?php

declare(strict_types=1);

namespace App\Core\Database\Drivers;

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;

/** What answered a probe: the server and its version ("MariaDB 10.4.32"), and how many tables its database holds. */
final class ProbeResult
{
    public function __construct(
        public readonly string $version,
        public readonly int $tables,
    ) {}

    /**
     * Connect with a driver's connection — one of the probe's own, made by Illuminate's connector exactly as the shop's
     * is (charset, session, options), closed afterwards — and say what answered.
     *
     * @param array<string, mixed> $connection DatabaseDriver::connection(), with whatever the probe adds (a timeout)
     * @param array<string, string> $minimum The oldest version taken of each server, by the connection's name for it
     *                                       (MySQL, MariaDB, SQLite); an older one is refused
     * @throws \PDOException when nothing answers: the driver words it
     * @throws ProbeFailedException
     */
    public static function probe(array $connection, array $minimum): self
    {
        $db = (new ConnectionFactory(new Container()))->make($connection, 'probe');

        try {
            $server = $db->getDriverTitle();
            $version = $db->getServerVersion();
            if (isset($minimum[$server]) && version_compare($version, $minimum[$server], '<')) {
                $supported = array_map(static fn(string $name, string $oldest): string => "{$name} {$oldest}", array_keys($minimum), $minimum);

                throw new ProbeFailedException("نسخه {$server} این سرور {$version} است؛ فروشگاه دست‌کم " . implode(' یا ', $supported) . ' می‌خواهد.');
            }

            $schema = $db->getSchemaBuilder();

            return new self("{$server} {$version}", count($schema->getTableListing($schema->getCurrentSchemaListing(), false)));
        } finally {
            $db->disconnect();
        }
    }

    /** @return array{version: string, tables: int} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'tables' => $this->tables];
    }
}
