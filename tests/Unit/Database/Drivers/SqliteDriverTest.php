<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use App\Core\Database\Drivers\DatabaseDriver;
use App\Core\Database\Drivers\ProbeFailedException;
use App\Core\Database\Drivers\ProbeResult;
use App\Core\Database\Drivers\SqliteDriver;
use App\Core\Exceptions\ValidationException;
use App\Core\Forms\Fields\Field;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * The SQLite driver against real files of its own: the tests' database and a developer's, never the installer's — what
 * it asks, the connection it makes (WAL and a busy timeout for a file), its probe, the dashboard's day and what a
 * rebuild drops before a table steps aside.
 */
final class SqliteDriverTest extends TestCase
{
    private string $base;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = dirname(__DIR__, 4);
        $this->dir = $this->scratchDir();
    }

    public function testItIsTheTestsDatabaseNotOneTheInstallerOffers(): void
    {
        $driver = new SqliteDriver($this->base);
        $descriptor = $driver->describe();

        self::assertSame(['sqlite', false, ['pdo_sqlite']], [$driver->key(), $descriptor->traits[DatabaseDriver::INSTALLABLE], $driver->extensions()]);
        self::assertSame(['path' => 'storage/amobot.sqlite'], $descriptor->form->values(static fn(Field $field): mixed => null));
        self::assertSame('DB_DATABASE', $descriptor->form->fields[0]->key);
        self::assertSame(['path', true], [$descriptor->toArray()['fields'][0]['type'], $descriptor->toArray()['fields'][0]['required']]);
    }

    public function testTheFileMustBeAFileOutsideTheWebRoot(): void
    {
        $form = (new SqliteDriver($this->base))->describe()->form;
        $nothing = static fn(Field $field): mixed => null;

        foreach (['' => 'وارد کنید', SqliteDriver::MEMORY => 'در حافظه', 'public/shop.sqlite' => 'public'] as $path => $why) {
            try {
                $form->check(['path' => $path], $nothing);
                self::fail("{$path} is no place for the shop's database");
            } catch (ValidationException $e) {
                self::assertStringContainsString($why, $e->errors()['path'][0]);
            }
        }

        self::assertSame(['DB_DATABASE' => 'storage/shop.sqlite'], $form->check(['path' => ' storage/shop.sqlite '], $nothing));
    }

    public function testAFileConnectionWaitsItsTurnAndKeepsForeignKeys(): void
    {
        $driver = new SqliteDriver($this->base);

        self::assertSame(['driver' => 'sqlite', 'database' => SqliteDriver::MEMORY, 'prefix' => '', 'foreign_key_constraints' => true], $driver->connection(['DB_DATABASE' => SqliteDriver::MEMORY]));

        $file = $driver->connection(['DB_DATABASE' => 'storage/shop.sqlite']);
        self::assertSame($this->base . '/storage/shop.sqlite', $file['database'], 'a relative path is the file\'s place in the application');
        self::assertSame([5000, 'wal', true], [$file['busy_timeout'], $file['journal_mode'], $file['foreign_key_constraints']]);
        self::assertSame($this->dir . '/shop.sqlite', $driver->connection(['DB_DATABASE' => $this->dir . '/shop.sqlite'])['database'], 'an absolute one is kept');
    }

    public function testTheProbeMakesTheFileAndCountsItsTables(): void
    {
        $driver = new SqliteDriver($this->base);
        $env = ['DB_DATABASE' => $this->dir . '/shop.sqlite'];

        $probe = $driver->probe($env);
        self::assertFileExists($this->dir . '/shop.sqlite');
        self::assertMatchesRegularExpression('/^SQLite 3\.\d+\.\d+$/', $probe->version);
        self::assertSame(0, $probe->tables);

        $db = self::open($driver->connection($env));
        $db->getSchemaBuilder()->create('teams', static fn(Blueprint $table) => $table->id());
        self::assertSame('wal', $db->scalar('pragma journal_mode'));
        $db->disconnect();
        self::assertSame(1, $driver->probe($env)->tables);

        $this->expectException(ProbeFailedException::class);
        $this->expectExceptionMessage('وجود ندارد');
        $driver->probe(['DB_DATABASE' => $this->dir . '/missing/shop.sqlite']);
    }

    public function testAFileTheProbeCannotMakeOrOpenIsSaidInTheAdminsWords(): void
    {
        $driver = new SqliteDriver($this->base);

        mkdir($this->dir . '/shop.sqlite');
        try {
            $driver->probe(['DB_DATABASE' => $this->dir . '/shop.sqlite']);
            self::fail('a folder stands where the file would be made');
        } catch (ProbeFailedException $e) {
            self::assertStringEndsWith('ساخته نشد.', $e->getMessage());
        }

        file_put_contents($this->dir . '/notes.sqlite', str_repeat('not a database ', 100));
        try {
            $driver->probe(['DB_DATABASE' => $this->dir . '/notes.sqlite']);
            self::fail('a file that is no database opened as one');
        } catch (ProbeFailedException $e) {
            self::assertStringStartsWith('فایل دیتابیس باز نشد. (SQLSTATE[HY000]', $e->getMessage(), 'with SQLite\'s own words');
        }

        self::assertSame(['notes.sqlite', 'shop.sqlite'], array_values(array_diff((array) scandir($this->dir), ['.', '..'])), 'nothing left behind');
    }

    public function testAServerOlderThanTheShopNeedsIsRefusedByName(): void
    {
        $this->expectException(ProbeFailedException::class);
        $this->expectExceptionMessageMatches('/^نسخه SQLite این سرور 3\.\d+\.\d+ است؛ فروشگاه دست‌کم SQLite 99 می‌خواهد\.$/');

        ProbeResult::probe((new SqliteDriver($this->base))->connection(['DB_DATABASE' => SqliteDriver::MEMORY]), ['SQLite' => '99']);
    }

    public function testTheShopsDayIsTheUtcMomentShiftedByWholeOrHalfHoursEitherWay(): void
    {
        $driver = new SqliteDriver($this->base);
        $db = self::open($driver->connection(['DB_DATABASE' => SqliteDriver::MEMORY]));
        $day = static fn(string $moment, int $offset): string => (string) $db->scalar('select ' . $driver->localDate('?', $offset), [$moment]);

        self::assertSame('2026-10-06', $day('2026-10-05 22:00:00', 12600), 'half past one in Tehran is the next day');
        self::assertSame('2026-10-05', $day('2026-10-05 20:29:59', 12600));
        self::assertSame('2026-10-04', $day('2026-10-05 04:00:00', -16200), 'half past eleven the night before at -04:30');
        self::assertSame('2026-10-05', $day('2026-10-05 23:59:59', 0));
    }

    public function testARebuildDropsTheIndexesWhoseNamesAreTheDatabasesAndKeepsTheKeys(): void
    {
        $driver = new SqliteDriver($this->base);
        $db = self::open($driver->connection(['DB_DATABASE' => SqliteDriver::MEMORY]));
        $schema = $db->getSchemaBuilder();
        $schema->create('teams', static fn(Blueprint $table) => $table->id());
        $schema->create('players', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams');
            $table->string('name')->unique();
            $table->string('nick')->index();
        });

        $driver->releaseNames($schema, 'players');

        self::assertSame([], array_values(array_filter($schema->getIndexes('players'), static fn(array $index): bool => !$index['primary'])));
        self::assertSame(['teams'], array_column($schema->getForeignKeys('players'), 'foreign_table'), 'a foreign key has no name of its own on SQLite');
    }

    /** @param array<string, mixed> $connection */
    private static function open(array $connection): Connection
    {
        return (new ConnectionFactory(new Container()))->make($connection);
    }
}
