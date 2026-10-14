<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Core\Application;
use App\Core\Database\Drivers\SqliteDriver;
use App\Core\Database\Schema;
use App\Core\Database\UpgradeFailedException;
use App\Core\Database\Upgrades;
use App\Core\Installation;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Support\PanelConnection;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Tests\TestCase;

/**
 * Database upgrades (Core\Database\Upgrades and database/upgrades/*.php): which files run and in what order, a failure
 * naming its version, and the rule every release keeps — a shop installed at the first release, upgraded by every file
 * the code ships (once, twice, or after a run that stopped half-way), has exactly the database a fresh install makes.
 */
final class UpgradesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private Connection $db;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = self::connection();
        $this->db->getSchemaBuilder()->create('upgrades_ran', static function (Blueprint $table): void {
            $table->string('version');
        });
        $this->folder = $this->scratchDir();
    }

    public function testEveryShippedUpgradeIsNamedByAVersionNoNewerThanTheCode(): void
    {
        foreach (glob(self::ROOT . '/database/upgrades/*.php') ?: [] as $file) {
            self::assertSame(1, preg_match(Upgrades::FILE, basename($file), $name), basename($file) . ' is named by the version it brings the database to');
            self::assertTrue(version_compare($name[1], Application::VERSION, '<='), basename($file) . ' is no newer than the code');
            self::assertInstanceOf(\Closure::class, require $file, basename($file) . ' returns its upgrade');
        }
    }

    public function testAShopInstalledAtTheFirstReleaseIsUpgradedToWhatAFreshInstallMakes(): void
    {
        $installed = self::firstRelease();
        $ran = [];

        self::upgradeToCode($installed, static function (string $version) use (&$ran): void {
            $ran[] = $version;
        });
        self::upgradeToCode($installed);

        self::assertSame(self::shape(self::freshInstall()), self::shape($installed), 'every change of database/schema.php since the first release ships in an upgrade');
        self::assertSame(array_keys((new Upgrades($installed))->pending(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, Application::VERSION)), $ran, 'each ran, in version order — and ran again, it changes nothing');
    }

    public function testAnUpgradeStoppedHalfWayIsFinishedByRunningItAgain(): void
    {
        // A run that stopped right after making a table, before its index and foreign key, and right after adding a
        // column to a table of the first release, before the column's index and foreign key.
        $installed = self::firstRelease();
        $installed->getSchemaBuilder()->create('sales', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('bot_id')->default(1);
            $table->string('name', 64);
            $table->unsignedTinyInteger('percent');
            $table->json('order_types')->nullable();
            $table->json('plan_ids')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        $installed->getSchemaBuilder()->table('orders', static function (Blueprint $table): void {
            $table->foreignId('discount_code_id')->nullable();
        });

        self::upgradeToCode($installed);

        self::assertSame(self::shape(self::freshInstall()), self::shape($installed));
    }

    public function testATermKeptInDaysIsKeptInHours(): void
    {
        $installed = self::firstRelease();
        $user = $installed->table('users')->insertGetId(['telegram_id' => 1]);
        $server = $installed->table('servers')->insertGetId(['name' => 'Berlin', 'driver' => 'fake', 'base_url' => 'https://berlin.test']);
        $monthly = $installed->table('plans')->insertGetId(['name' => 'یک‌ماهه', 'price' => '120000', 'duration_days' => 30]);
        $installed->table('plans')->insert(['name' => 'نامحدود', 'price' => '200000', 'duration_days' => 0]);
        $installed->table('subscriptions')->insert(['user_id' => $user, 'plan_id' => $monthly, 'server_id' => $server, 'remote_name' => 'ali_1', 'subscription_url' => 'https://berlin.test/sub/1', 'duration_days' => 33]);

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        self::assertSame(['720', '0'], $installed->table('plans')->orderBy('id')->pluck('duration_hours')->map(strval(...))->all(), 'days × 24, and «never» stays 0 — once, however often it runs');
        self::assertSame('792', (string) $installed->table('subscriptions')->value('duration_hours'));
        self::assertFalse($installed->getSchemaBuilder()->hasColumn('subscriptions', 'duration_days'));
    }

    public function testAShopsReferralRateCarriesOverToRenewals(): void
    {
        $installed = self::firstRelease();
        $agent = $installed->table('bots')->insertGetId(['title' => 'agent']);
        $installed->table('settings')->insert([
            ['bot_id' => 1, 'key' => 'referral.rate', 'value' => '15'],
            ['bot_id' => $agent, 'key' => 'referral.rate', 'value' => '20'],
            ['bot_id' => $agent, 'key' => 'referral.renewal_rate', 'value' => '5'],
        ]);

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        $renewal = $installed->table('settings')->where('key', 'referral.renewal_rate')->orderBy('bot_id')->pluck('value', 'bot_id')->all();
        self::assertSame([1 => '15', $agent => '5'], $renewal, 'a rate the shop set carries over; one set already stays — once, however often it runs');
    }

    public function testTheServicesThatEndedAreGivenWhenTheyEnded(): void
    {
        $installed = self::firstRelease();
        $user = $installed->table('users')->insertGetId(['telegram_id' => 1]);
        $server = $installed->table('servers')->insertGetId(['name' => 'Berlin', 'driver' => 'fake', 'base_url' => 'https://berlin.test']);
        $past = gmdate('Y-m-d H:i:s', time() - 86400);
        $read = gmdate('Y-m-d H:i:s', time() - 3600);
        $service = static fn(string $name, string $status, ?string $expires): array => [
            'user_id' => $user, 'server_id' => $server, 'remote_name' => $name, 'subscription_url' => "https://berlin.test/sub/{$name}",
            'status' => $status, 'expires_at' => $expires, 'last_synced_at' => $read,
        ];
        $installed->table('subscriptions')->insert([
            $service('timed_out', 'expired', $past),
            $service('used_up', 'expired', gmdate('Y-m-d H:i:s', time() + 86400)),
            $service('gone', 'deleted', null),
            $service('running', 'active', gmdate('Y-m-d H:i:s', time() + 86400)),
        ]);

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        $ended = $installed->table('subscriptions')->orderBy('id')->pluck('ended_at', 'remote_name')->all();
        self::assertSame(['timed_out' => $past, 'used_up' => $read, 'gone' => $read, 'running' => null], $ended, 'the deadline it passed, else its last read — once');
    }

    public function testEveryRenewalSoFarExtendedItsService(): void
    {
        $installed = self::firstRelease();
        $user = $installed->table('users')->insertGetId(['telegram_id' => 1]);
        $order = static fn(string $type): array => ['user_id' => $user, 'type' => $type, 'amount' => '120000'];
        $installed->table('orders')->insert([$order('renewal'), $order('purchase'), $order('topup')]);

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        self::assertSame(['extend', null, null], $installed->table('orders')->orderBy('id')->pluck('renewal_method')->all(), 'a renewal extended its service; other orders have no method');
    }

    public function testACashbackRuleForEveryKindOfOrderIsForExtraDevicesToo(): void
    {
        $installed = self::firstRelease();
        (new Upgrades($installed))->run(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, '0.3.0', static function (): void {});
        $rule = static fn(?array $kinds): array => ['percent' => 5, 'order_types' => $kinds === null ? null : json_encode($kinds)];
        $installed->table('cashback_rules')->insert([
            $rule(['purchase', 'renewal', 'extra_traffic', 'extra_time']),
            $rule(['renewal', 'purchase', 'extra_time', 'extra_traffic']),
            $rule(['purchase', 'renewal']),
            $rule(null),
        ]);

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        $kinds = $installed->table('cashback_rules')->orderBy('id')->pluck('order_types')->map(static fn(?string $kinds): ?array => $kinds === null ? null : json_decode($kinds, true))->all();
        $every = ['purchase', 'renewal', 'extra_traffic', 'extra_time', 'extra_devices'];
        self::assertSame([$every, $every, ['purchase', 'renewal'], null], $kinds, 'every kind 0.3.0 sold is every kind still; fewer, or none named (purchases and renewals), stay — once, however often it runs');
    }

    public function testACardMethodsCardBecomesItsFirstCardRowAndEveryPaymentOfItWasShownIt(): void
    {
        $installed = self::firstRelease();
        (new Upgrades($installed))->run(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, '0.4.0', static function (): void {});
        $agent = $installed->table('bots')->insertGetId(['title' => 'agent']);
        $method = static fn(int $bot, array $config): array => ['bot_id' => $bot, 'driver' => 'manual', 'label' => 'کارت', 'config' => json_encode($config), 'enabled' => true, 'sort' => 2];
        $mellat = $installed->table('payment_methods')->insertGetId($method(1, ['card_number' => '6037997700001119', 'card_holder' => ' Sara ', 'instructions' => 'رسید را بفرستید.', 'auto_approve_after' => 30]));
        // Kept as typed (its form read a kept number so): Persian digits, a dash and a space.
        $theirs = $installed->table('payment_methods')->insertGetId($method($agent, ['card_number' => '۵۸۹۲-۱۰۱۰ ۱۲۳۴ ۵۶۷۰', 'card_holder' => 'Reza']));
        $broken = $installed->table('payment_methods')->insertGetId($method(1, ['card_number' => '6037997700001111', 'card_holder' => 'AmoBot']));
        $user = $installed->table('users')->insertGetId(['telegram_id' => 1]);
        $order = $installed->table('orders')->insertGetId(['user_id' => $user, 'type' => 'topup', 'amount' => '100000']);
        foreach ([$mellat, $mellat, $broken] as $paidWith) {
            $installed->table('payments')->insert(['order_id' => $order, 'payment_method_id' => $paidWith, 'amount' => '100000']);
        }

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        $cards = $installed->table('payment_cards')->orderBy('id')->get(['bot_id', 'payment_method_id', 'number', 'holder', 'bank', 'enabled', 'sort', 'daily_cap'])->map(static fn(object $card): array => (array) $card)->all();
        self::assertEquals([
            ['bot_id' => 1, 'payment_method_id' => $mellat, 'number' => '6037997700001119', 'holder' => 'Sara', 'bank' => null, 'enabled' => 1, 'sort' => 1, 'daily_cap' => null],
            ['bot_id' => $agent, 'payment_method_id' => $theirs, 'number' => '5892101012345670', 'holder' => 'Reza', 'bank' => null, 'enabled' => 1, 'sort' => 1, 'daily_cap' => null],
        ], $cards, "each method's card a row of its shop, its bare digits — once, however often it runs; a number its form would refuse makes none");
        $card = $installed->table('payment_cards')->where('payment_method_id', $mellat)->value('id');
        self::assertSame([$card, $card, null], $installed->table('payments')->orderBy('id')->pluck('card_id')->all(), 'its payments were shown it');
        $configs = $installed->table('payment_methods')->whereIn('id', [$mellat, $theirs, $broken])->orderBy('id')->pluck('config')->map(static fn(string $config): array => json_decode($config, true))->all();
        self::assertSame([['instructions' => 'رسید را بفرستید.', 'auto_approve_after' => 30], [], []], $configs, 'the card is no setting any more; the rest stays');
    }

    public function testAnUpgradeThatStopsBeforeItsEndLeavesTheCardWhereTheCodePutBackReadsIt(): void
    {
        $installed = self::firstRelease();
        (new Upgrades($installed))->run(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, '0.4.0', static function (): void {});
        $card = ['card_number' => '6037997700001119', 'card_holder' => 'Sara'];
        $method = $installed->table('payment_methods')->insertGetId(['bot_id' => 1, 'driver' => 'manual', 'label' => 'کارت', 'config' => json_encode($card), 'enabled' => true, 'sort' => 2]);
        $config = static fn(): array => json_decode((string) $installed->table('payment_methods')->where('id', $method)->value('config'), true);
        // A later step fails — the disk is full when the outside row is written.
        $installed->statement("CREATE TRIGGER disk_full BEFORE INSERT ON payment_methods WHEN NEW.driver = 'outside' BEGIN SELECT RAISE(ABORT, 'database or disk is full'); END");

        try {
            self::upgradeToCode($installed);
            self::fail('The upgrade went through.');
        } catch (UpgradeFailedException) {
            // Stopped half-way: the update puts the code it came from back.
        }
        self::assertSame($card, $config(), 'the card 0.4.0 shows, still where it reads it');

        $installed->statement('DROP TRIGGER disk_full');
        self::upgradeToCode($installed);

        self::assertSame([], $config(), 'gone once the run is through');
        self::assertSame(1, $installed->table('payment_cards')->where('payment_method_id', $method)->count(), 'its one card row, however often it ran');
    }

    public function testEveryShopGetsTheOutsideRowAfterItsOtherMethods(): void
    {
        $installed = self::firstRelease();
        (new Upgrades($installed))->run(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, '0.4.0', static function (): void {});
        $installed->table('bots')->insert(['id' => 2]);
        $installed->table('payment_methods')->insert([
            ['bot_id' => 2, 'driver' => 'wallet', 'label' => 'کیف پول', 'config' => '[]', 'enabled' => true, 'sort' => 1],
            ['bot_id' => 2, 'driver' => 'manual', 'label' => 'کارت', 'config' => '[]', 'enabled' => true, 'sort' => 4],
        ]);

        self::upgradeToCode($installed);
        self::upgradeToCode($installed);

        $outside = $installed->table('payment_methods')->where('driver', 'outside')->orderBy('bot_id')->get(['bot_id', 'label', 'sort'])->map(static fn(object $row): array => [(int) $row->bot_id, $row->label, (int) $row->sort])->all();
        self::assertSame([[1, 'خارج از فروشگاه', 2], [2, 'خارج از فروشگاه', 5]], $outside, 'one each, after the shop\'s other methods — once, however often it runs');
    }

    public function testAServerKeptBeforeTheProxyIsReachedStraightAsBefore(): void
    {
        $installed = self::firstRelease();
        (new Upgrades($installed))->run(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, '0.4.0', static function (): void {});
        $installed->table('servers')->insert(['name' => 'Berlin', 'driver' => 'fake', 'base_url' => 'https://berlin.test:2053/base', 'meta' => '{"timeout":45}']);

        self::upgradeToCode($installed);

        $server = (new Server())->newFromBuilder((array) $installed->table('servers')->first());
        self::assertSame([false, null, null, null, null], [$server->proxy_enabled, $server->proxy_host, $server->proxy_port, $server->proxy_username, $server->getRawOriginal('proxy_password')], 'no proxy');
        $connection = PanelConnection::of($server);
        self::assertSame(['https://berlin.test:2053/base', 45, null], [$connection->baseUrl, $connection->timeout, $connection->proxy], 'its panel reached straight, its options as they were');
    }

    public function testTheUpgradesBetweenTwoVersionsRunInVersionOrder(): void
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

    public function testAFileNamedByNoVersionStopsIt(): void
    {
        file_put_contents($this->folder . '/add-a-column.php', '<?php return null;');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('add-a-column.php');
        (new Upgrades($this->db))->pending($this->folder, '0.1.0', '0.2.0');
    }

    public function testAnUpgradeThatReturnsNoClosureFails(): void
    {
        file_put_contents($this->folder . '/0.2.0.php', '<?php return 42;');

        $this->expectException(UpgradeFailedException::class);
        (new Upgrades($this->db))->run($this->folder, '0.1.0', '0.2.0', static function (): void {});
    }

    private static function connection(): Connection
    {
        return (new ConnectionFactory(new Container()))->make((new SqliteDriver(self::ROOT))->connection(['DB_DATABASE' => SqliteDriver::MEMORY]));
    }

    /** A database as the first release installed it (tests/Fixtures/schema-0.1.0.php, kept as it shipped). */
    private static function firstRelease(): Connection
    {
        $db = self::connection();
        (new Schema($db, new SqliteDriver(self::ROOT), require self::ROOT . '/tests/Fixtures/schema-0.1.0.php'))->create();

        return $db;
    }

    private static function freshInstall(): Connection
    {
        $db = self::connection();
        (new Schema($db, new SqliteDriver(self::ROOT), require self::ROOT . '/database/schema.php'))->create();

        return $db;
    }

    /** @param ?\Closure(string): void $ran */
    private static function upgradeToCode(Connection $db, ?\Closure $ran = null): void
    {
        (new Upgrades($db))->run(self::ROOT . '/database/upgrades', Installation::FIRST_RELEASE, Application::VERSION, $ran ?? static function (): void {});
    }

    /**
     * Every table with its columns (name, type, null or not, default, generated), its indexes (name, columns, unique,
     * primary) and its foreign keys (columns, the table and columns they point at, on update and on delete), each sorted:
     * what two databases must share to be the same.
     *
     * @return array<string, array{columns: list<list<mixed>>, indexes: list<list<mixed>>, foreign: list<list<mixed>>}>
     */
    private static function shape(Connection $db): array
    {
        $schema = $db->getSchemaBuilder();
        $shape = [];
        foreach ($schema->getTableListing(schemaQualified: false) as $table) {
            $columns = array_map(static fn(array $column): array => [$column['name'], $column['type_name'], $column['nullable'], $column['default'], $column['generation']], $schema->getColumns($table));
            $indexes = array_map(static fn(array $index): array => [$index['name'], $index['columns'], $index['unique'], $index['primary']], $schema->getIndexes($table));
            $foreign = array_map(static fn(array $key): array => [$key['columns'], $key['foreign_table'], $key['foreign_columns'], $key['on_update'], $key['on_delete']], $schema->getForeignKeys($table));
            sort($columns);
            sort($indexes);
            sort($foreign);
            $shape[$table] = ['columns' => $columns, 'indexes' => $indexes, 'foreign' => $foreign];
        }
        ksort($shape);

        return $shape;
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
