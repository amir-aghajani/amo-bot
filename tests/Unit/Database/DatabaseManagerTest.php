<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Config\ConfigValues;
use App\Core\Config\Repository as Config;
use App\Core\Database\DatabaseManager;
use App\Core\Drivers\UnknownDriverException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * The shop's database is the driver DB_CONNECTION names, made from every DB_* setting as config.php writes it: Eloquent
 * gets that driver's connection under the driver's key, and a name no driver has stops the boot before anything is
 * connected — as a broken config.php does. (Whether the database answers, and who is told why not, is the health
 * check's: SecurityHeadersTest.)
 */
final class DatabaseManagerTest extends TestCase
{
    public function testTheShopRunsOnTheDriverDbConnectionNames(): void
    {
        $manager = $this->service(DatabaseManager::class);

        self::assertSame('sqlite', $manager->driver()->key(), 'the suite\'s config.php names sqlite');
        self::assertSame('sqlite', $this->service(Connection::class)->getName(), 'Eloquent\'s connection, named after the driver');
        self::assertTrue($manager->answers());
    }

    public function testTheDriverReadsItsSettingsAsConfigPhpWritesThem(): void
    {
        $part = require $this->app()->configPath('database.php');
        self::assertInstanceOf(\Closure::class, $part);
        $config = $part(new ConfigValues(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'null', 'DB_SOCKET' => 'true', 'DB_PORT' => 3307, 'APP_NAME' => 'AmoBot']));

        self::assertSame('mysql', $config['driver']);
        self::assertSame(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'null', 'DB_SOCKET' => 'true', 'DB_PORT' => '3307'], $config['connection'], 'as text and uncoerced — a password may well read "null" —, the DB_ settings alone');
    }

    public function testADriverNoOneHasStopsTheBootBeforeAnythingIsConnected(): void
    {
        $capsule = new Capsule();
        $manager = new DatabaseManager(new Config(['database' => ['driver' => 'pgsql', 'connection' => []]]), $capsule, $this->app()->container()->get('database.drivers'), new NullLogger());

        try {
            $manager->boot();
            self::fail('the shop booted on a database no driver speaks');
        } catch (UnknownDriverException $e) {
            self::assertStringContainsString('DB_CONNECTION "pgsql"', $e->getMessage(), 'the message says which setting to fix');
        }
        self::assertSame([], $capsule->getDatabaseManager()->getConnections());
    }
}
