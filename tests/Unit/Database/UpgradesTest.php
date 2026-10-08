<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Application;
use App\Core\Database\Drivers\SqliteDriver;
use App\Core\Database\UpgradeFailedException;
use App\Core\Database\Upgrades;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Tests\TestCase;

/**
 * The database's way from one release to the next (Core\Database\Upgrades), on a database of its own: the upgrades a
 * release ships named by the version each brings the database to — none newer than the code —, run in version order from
 * the version the database is at to the code's, each heard as it ran, and one that fails named with the ones before it
 * standing.
 */
final class UpgradesTest extends TestCase
{
    private Connection $db;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = (new ConnectionFactory(new Container()))->make((new SqliteDriver(dirname(__DIR__, 3)))->connection(['DB_DATABASE' => SqliteDriver::MEMORY]));
        $this->db->getSchemaBuilder()->create('upgrades_ran', static function (Blueprint $table): void {
            $table->string('version');
        });
        $this->folder = $this->scratchDir();
    }

    public function testEveryUpgradeTheShopShipsIsNamedByAVersionNoNewerThanItsCodeAndIsOne(): void
    {
        foreach (glob(dirname(__DIR__, 3) . '/database/upgrades/*.php') ?: [] as $file) {
            self::assertSame(1, preg_match(Upgrades::FILE, basename($file), $name), basename($file) . ' is named by the version it brings the database to');
            self::assertTrue(version_compare($name[1], Application::VERSION, '<='), basename($file) . ' is no newer than the code');
            self::assertInstanceOf(\Closure::class, require $file, basename($file) . ' returns its upgrade');
        }
        self::assertFileExists(dirname(__DIR__, 3) . '/database/upgrades/README.md', 'the folder says its rule');
    }

    public function testTheUpgradesBetweenTwoVersionsRunInVersionOrderEachHeardAsItRan(): void
    {
        foreach (['0.1.0', '0.2.0', '0.3.0', '0.10.0', '0.11.0'] as $version) {
            $this->upgrade($version);
        }

        $ran = [];
        (new Upgrades($this->db))->run($this->folder, '0.1.0', '0.10.0', static function (string $version) use (&$ran): void {
            $ran[] = $version;
        });

        self::assertSame(['0.2.0', '0.3.0', '0.10.0'], $ran, 'newer than the database, not newer than the code — 0.10.0 after 0.3.0, by version');
        self::assertSame($ran, $this->db->table('upgrades_ran')->pluck('version')->all(), 'each ran, in that order');
        self::assertSame(['0.2.0', '0.3.0', '0.10.0'], array_keys((new Upgrades($this->db))->pending($this->folder, '0.1.0', '0.10.0')));
        self::assertSame([], (new Upgrades($this->db))->pending($this->folder, '0.11.0', '0.11.0'), 'a database at the code\'s version has none to run');
    }

    public function testAnUpgradeThatFailsIsNamedAndTheOnesBeforeItStand(): void
    {
        $this->upgrade('0.2.0');
        file_put_contents($this->folder . '/0.3.0.php', '<?php return static function (): void { throw new RuntimeException("no room for the new column"); };');
        $this->upgrade('0.4.0');
        $ran = [];

        try {
            (new Upgrades($this->db))->run($this->folder, '0.1.0', '0.4.0', static function (string $version) use (&$ran): void {
                $ran[] = $version;
            });
            self::fail('The upgrade to 0.3.0 failed.');
        } catch (UpgradeFailedException $e) {
            self::assertSame('0.3.0', $e->version);
            self::assertSame('no room for the new column', $e->getPrevious()?->getMessage());
        }

        self::assertSame(['0.2.0'], $ran, 'where the database is: the next try starts at 0.3.0');
        self::assertSame(['0.2.0'], $this->db->table('upgrades_ran')->pluck('version')->all(), '0.4.0 waits for it');
    }

    public function testAFileThatIsNoUpgradeStopsIt(): void
    {
        file_put_contents($this->folder . '/add-a-column.php', '<?php return null;');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('add-a-column.php');
        (new Upgrades($this->db))->pending($this->folder, '0.1.0', '0.2.0');
    }

    public function testAnUpgradeThatReturnsNoneFails(): void
    {
        file_put_contents($this->folder . '/0.2.0.php', '<?php return 42;');

        $this->expectException(UpgradeFailedException::class);
        (new Upgrades($this->db))->run($this->folder, '0.1.0', '0.2.0', static function (): void {});
    }

    /** An upgrade to `$version` that writes down that it ran. */
    private function upgrade(string $version): void
    {
        file_put_contents("{$this->folder}/{$version}.php", sprintf(
            '<?php return static function (%s $schema, %s $db): void { $db->table("upgrades_ran")->insert(["version" => "%s"]); };',
            Builder::class,
            Connection::class,
            $version,
        ));
    }
}
