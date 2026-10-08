<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use App\Core\Database\Drivers\DatabaseDriver;
use App\Core\Database\Drivers\MySqlDriver;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use PHPUnit\Framework\TestCase;

/**
 * The MySQL / MariaDB driver without a server: what its form asks the admin, the config.php settings and the connection
 * it makes of the answers — the password kept only while the address it was given for stays —, its SQL for the
 * dashboard's day, what a rebuild drops before a table steps aside, and how a refusal reads.
 */
final class MySqlDriverTest extends TestCase
{
    private const INPUT = ['host' => 'db.example', 'port' => '3307', 'database' => 'shop_db', 'username' => 'shop', 'password' => 'p@ss', 'socket' => '', 'prefix' => 'amo_'];

    /** What config.php holds for a database at db.example:3307. */
    private const STORED = ['DB_HOST' => 'db.example', 'DB_PORT' => '3307', 'DB_PASSWORD' => 'stored'];

    public function testItIsTheDatabaseTheInstallerOffersWithItsFieldsInOrder(): void
    {
        $driver = new MySqlDriver();
        $descriptor = $driver->describe();
        $fields = $descriptor->toArray()['fields'];

        self::assertSame(['mysql', 'mysql', true], [$driver->key(), $descriptor->key, $descriptor->traits[DatabaseDriver::INSTALLABLE]]);
        self::assertSame(['pdo_mysql'], $driver->extensions());
        self::assertSame(['host', 'port', 'database', 'username', 'password', 'socket', 'prefix'], array_column($fields, 'name'));
        self::assertSame(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SOCKET', 'DB_PREFIX'], array_map(static fn(Field $field): string => $field->key, $descriptor->form->fields));
        self::assertSame(['password'], array_column(array_filter($fields, static fn(array $field): bool => $field['secret']), 'name'));
        self::assertSame(['host', 'port', 'socket'], array_column($fields, 'bound_to', 'name')['password'], 'where the database is: what its password stays with');
        self::assertSame(['socket', 'prefix'], array_column(array_filter($fields, static fn(array $field): bool => $field['advanced']), 'name'));
        self::assertSame(['localhost', 3306, 'amobot', 'root'], array_slice(array_values($descriptor->form->values(static fn(Field $field): mixed => null)), 0, 4), 'what the shop runs with while config.php does not say');
    }

    public function testEveryFieldIsCheckedInPersianUnderItsOwnName(): void
    {
        try {
            self::check(['host' => 'bad host', 'port' => '0', 'database' => 'shop db', 'username' => '', 'socket' => 'mysqld.sock', 'prefix' => 'amo-'], []);
            self::fail('nothing there is a database');
        } catch (ValidationException $e) {
            self::assertSame(['host', 'port', 'database', 'username', 'socket', 'prefix'], array_keys($e->errors()));
            self::assertSame('پورت باید عددی بین 1 تا 65535 باشد.', $e->errors()['port'][0]);
        }
    }

    public function testTheAnswersAreTheDriversSettingsWithASecretKeptReplacedOrCleared(): void
    {
        self::assertSame(
            ['DB_HOST' => 'db.example', 'DB_PORT' => 3307, 'DB_DATABASE' => 'shop_db', 'DB_USERNAME' => 'shop', 'DB_PASSWORD' => 'p@ss', 'DB_SOCKET' => '', 'DB_PREFIX' => 'amo_'],
            self::check(self::INPUT, self::STORED),
        );
        self::assertSame(3307, self::check(['port' => '۳۳۰۷'] + self::INPUT, self::STORED)['DB_PORT'], 'Persian digits are a port too');
        self::assertSame('stored', self::check(['password' => '', 'port' => '۳۳۰۷'] + self::INPUT, self::STORED)['DB_PASSWORD'], 'a blank secret keeps the stored one while the address stays');
        self::assertSame('', self::check(['password' => '', 'clear_password' => true] + self::INPUT, self::STORED)['DB_PASSWORD']);
        self::assertSame('/run/mysqld/mysqld.sock', self::check(['socket' => '/run/mysqld/mysqld.sock', 'password' => 'its own'] + self::INPUT, self::STORED)['DB_SOCKET']);

        try {
            self::check(['host' => 'db.elsewhere.example', 'password' => ''] + self::INPUT, self::STORED);
            self::fail('the stored password would go to another server');
        } catch (ValidationException $e) {
            self::assertSame(['password' => [MySqlDriver::ADDRESS_MOVED]], $e->errors());
        }
    }

    public function testTheConnectionSpeaksUtcInStrictUtf8mb4FromTheSettingsOrTheirDefaults(): void
    {
        $driver = new MySqlDriver();

        $default = $driver->connection([]);
        self::assertSame(
            ['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306, 'database' => 'amobot', 'username' => 'root', 'password' => '', 'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => ''],
            array_intersect_key($default, array_flip(['driver', 'host', 'port', 'database', 'username', 'password', 'unix_socket', 'charset', 'collation', 'prefix'])),
        );
        self::assertSame(['+00:00', true], [$default['timezone'], $default['strict']]);

        $connection = $driver->connection(self::check(self::INPUT, []));
        self::assertSame(['db.example', 3307, 'shop_db', 'shop', 'p@ss', 'amo_'], [$connection['host'], $connection['port'], $connection['database'], $connection['username'], $connection['password'], $connection['prefix']]);
        self::assertSame(3307, $driver->connection(['DB_PORT' => '3307'])['port'], 'as config.php writes it, text');
    }

    public function testTheShopsDayIsTheUtcMomentShiftedByWholeOrHalfHoursEitherWay(): void
    {
        $driver = new MySqlDriver();

        self::assertSame('DATE(DATE_ADD(`paid_at`, INTERVAL 12600 SECOND))', $driver->localDate('`paid_at`', 12600), 'Tehran, +03:30');
        self::assertSame('DATE(DATE_ADD(`paid_at`, INTERVAL -16200 SECOND))', $driver->localDate('`paid_at`', -16200), 'Caracas once, -04:30');
        self::assertSame('DATE(DATE_ADD(`created_at`, INTERVAL 0 SECOND))', $driver->localDate('`created_at`', 0));
    }

    public function testARebuildDropsTheForeignKeysWhoseNamesAreTheDatabases(): void
    {
        // The commands are what is looked at: any connection's grammar builds the blueprint.
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'));
        $connection->useDefaultSchemaGrammar();
        $schema = new class ($connection) extends Builder {
            /** @var list<array{name: string}> */
            public array $keys = [];

            /** @var list<array{name: string, index: string}> */
            public array $commands = [];

            /**
             * @param string $table
             * @return list<array{name: string}>
             */
            public function getForeignKeys($table): array
            {
                return $this->keys;
            }

            /** @param string $table */
            public function table($table, \Closure $callback): void
            {
                $blueprint = new Blueprint($this->connection, $table);
                $callback($blueprint);
                foreach ($blueprint->getCommands() as $command) {
                    $this->commands[] = ['name' => (string) $command->get('name'), 'index' => (string) $command->get('index')];
                }
            }
        };
        $driver = new MySqlDriver();

        $driver->releaseNames($schema, 'players');
        self::assertSame([], $schema->commands, 'a table without foreign keys is left as it is');

        $schema->keys = [['name' => 'players_team_id_foreign'], ['name' => 'players_coach_id_foreign']];
        $driver->releaseNames($schema, 'players');
        self::assertSame([['name' => 'dropForeign', 'index' => 'players_team_id_foreign'], ['name' => 'dropForeign', 'index' => 'players_coach_id_foreign']], $schema->commands);
    }

    public function testARefusalIsWordedFromTheServersErrorNumberWithItsOwnMessage(): void
    {
        self::assertSame('نام کاربری یا رمز عبور دیتابیس پذیرفته نشد. (SQLSTATE[HY000] [1045] Access denied)', MySqlDriver::explain(new \PDOException('SQLSTATE[HY000] [1045] Access denied', 1045)));
        self::assertStringStartsWith('نام کاربری یا رمز عبور', MySqlDriver::explain(new \PDOException('auth_socket', 1698)));
        self::assertStringStartsWith('این کاربر به دیتابیس دسترسی ندارد.', MySqlDriver::explain(new \PDOException('denied to database', 1044)));
        self::assertStringStartsWith('دیتابیسی با این نام وجود ندارد', MySqlDriver::explain(new \PDOException('Unknown database', 1049)));
        foreach ([2002, 2003, 2005] as $code) {
            self::assertStringStartsWith('اتصال به سرور دیتابیس برقرار نشد', MySqlDriver::explain(new \PDOException('unreachable', $code)));
        }
        self::assertStringStartsWith('اتصال به دیتابیس ناموفق بود.', MySqlDriver::explain(new \PDOException('something else', 1234)));

        $failed = new \PDOException('SQLSTATE[HY000]: General error');
        $failed->errorInfo = ['HY000', 1049, 'Unknown database'];
        self::assertStringStartsWith('دیتابیسی با این نام وجود ندارد', MySqlDriver::explain($failed), "a query's error number is in its errorInfo");
    }

    /**
     * The driver's form as a screen saves it, against what config.php holds.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $stored
     * @return array<string, mixed>
     */
    private static function check(array $input, array $stored): array
    {
        return (new MySqlDriver())->describe()->form->check($input, static fn(Field $field): mixed => $stored[$field->key] ?? null);
    }
}
