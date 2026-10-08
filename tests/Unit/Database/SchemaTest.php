<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Console\Commands\DbRebuildCommand;
use App\Core\Database\Drivers\MySqlDriver;
use App\Core\Database\Drivers\SqliteDriver;
use App\Core\Database\Schema;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Fluent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The shop's tables on a database of their own: database/schema.php made in an order MySQL accepts, only what a
 * database lacks made, and a rebuild carrying the rows over to a changed shape — or stopping without losing one.
 */
final class SchemaTest extends TestCase
{
    private SqliteDriver $driver;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Made by the driver as the app's is — foreign keys on, so one the rebuild left out would show.
        $this->driver = new SqliteDriver(dirname(__DIR__, 3));
        $this->db = (new ConnectionFactory(new Container()))->make($this->driver->connection(['DB_DATABASE' => SqliteDriver::MEMORY]));
    }

    public function testTheShopsSchemaMakesEveryTableItNamesAndNothingElse(): void
    {
        $schema = $this->shop();

        self::assertSame($schema->tables(), $schema->missing(), 'an empty database lacks them all');
        self::assertSame($schema->tables(), $schema->create());
        self::assertSame([], $schema->missing());
        self::assertSame([], $schema->create(), 'nothing is made twice');

        $made = $this->tablesInDatabase();
        $named = $schema->tables();
        sort($named);
        self::assertSame($named, $made);
        self::assertSame(['wallet'], $this->db->table('payment_methods')->pluck('driver')->all(), 'a new shop starts with its wallet');
    }

    public function testEveryForeignKeyPointsAtATableMadeBeforeIt(): void
    {
        // MySQL refuses a foreign key to a table that is not there yet; SQLite would not notice.
        $schema = $this->shop();
        $schema->create();
        $position = array_flip($schema->tables());

        foreach ($schema->tables() as $table) {
            foreach ($this->db->getSchemaBuilder()->getForeignKeys($table) as $key) {
                self::assertLessThanOrEqual($position[$table], $position[$key['foreign_table']], "{$table} points at {$key['foreign_table']}, which is made after it");
            }
        }
    }

    /**
     * MySQL holds a key's name to 64 characters, and the table prefix begins every name the schema makes (the driver takes
     * one of up to MySqlDriver::PREFIX_MAX): a longer name stops the installer and db:rebuild there. A primary key has no
     * name of its own on MySQL (PRIMARY).
     */
    public function testEveryKeyNameFitsMySqlAfterTheLongestPrefix(): void
    {
        $prefixed = (new ConnectionFactory(new Container()))->make(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => str_repeat('p', MySqlDriver::PREFIX_MAX), 'prefix_indexes' => true]);
        $prefixed->useDefaultSchemaGrammar();

        $names = 0;
        foreach ($this->definitions() as $table => $define) {
            $blueprint = new Blueprint($prefixed, $table, $define);
            $blueprint->create();
            $blueprint->toSql();
            foreach ($blueprint->getCommands() as $command) {
                if (in_array($command->get('name'), ['index', 'unique', 'foreign'], true)) {
                    $name = (string) $command->get('index');
                    self::assertLessThanOrEqual(64, strlen($name), "{$table}: {$name}");
                    $names++;
                }
            }
        }
        self::assertGreaterThan(50, $names, 'every table\'s keys were looked at');
    }

    /**
     * MySQL takes no foreign key that cascades — CASCADE, SET NULL or SET DEFAULT, on delete or update — on a column a
     * stored generated column is computed from; MariaDB does, so only this says so before a MySQL host does.
     */
    public function testNoForeignKeyCascadesOnAColumnAGeneratedOneIsComputedFrom(): void
    {
        $this->db->useDefaultSchemaGrammar();

        $checked = 0;
        foreach ($this->definitions() as $table => $define) {
            $blueprint = new Blueprint($this->db, $table, $define);
            $computedFrom = implode(' ', array_map(static fn(Fluent $column): string => (string) $column->get('storedAs', ''), $blueprint->getColumns()));
            foreach ($blueprint->getCommands() as $command) {
                foreach ($command->get('name') === 'foreign' ? (array) $command->get('columns') : [] as $column) {
                    if (preg_match('/\b' . preg_quote((string) $column, '/') . '\b/', $computedFrom) !== 1) {
                        continue;
                    }
                    $checked++;
                    foreach (['onDelete', 'onUpdate'] as $action) {
                        self::assertNotContains(strtolower((string) $command->get($action, '')), ['cascade', 'set null', 'set default'], "{$table}.{$column} {$action}");
                    }
                }
            }
        }
        self::assertGreaterThan(0, $checked, 'grant_parts.server_id, which running_server_id is computed from');
    }

    /**
     * A TIMESTAMP that may not be null needs a default of its own: MySQL and MariaDB in strict mode (the connection's)
     * with NO_ZERO_DATE refuse the table otherwise — its implicit default is the zero date — and SQLite, the tests'
     * database, takes it, so only this says so before a host does (`db:rebuild` stopped on one).
     */
    public function testEveryTimestampThatMayNotBeNullHasADefault(): void
    {
        $this->db->useDefaultSchemaGrammar();

        foreach ($this->definitions() as $table => $define) {
            foreach ((new Blueprint($this->db, $table, $define))->getColumns() as $column) {
                if (in_array($column->get('type'), ['timestamp', 'timestampTz', 'dateTime', 'dateTimeTz'], true) && $column->get('nullable') !== true) {
                    self::assertTrue($column->get('useCurrent') === true || $column->get('default') !== null, "{$table}.{$column->get('name')}: nullable(), useCurrent() or a default");
                }
            }
        }
    }

    public function testOnlyTheTablesADatabaseLacksAreMade(): void
    {
        $schema = $this->shop();
        $schema->create();
        $this->db->getSchemaBuilder()->drop('custom_emojis');

        self::assertSame(['custom_emojis'], $schema->missing());
        self::assertSame(['custom_emojis'], $schema->create());
        self::assertSame(1, $this->db->table('payment_methods')->count(), 'a table that was there gets no starting rows');
    }

    public function testARebuildCarriesTheRowsOverToTheNewShape(): void
    {
        (new Schema($this->db, $this->driver, ['tables' => ['teams' => self::teams(...), 'players' => self::players(...)]]))->create();
        $this->db->table('teams')->insert([['id' => 3, 'name' => 'blue', 'motto' => 'go'], ['id' => 7, 'name' => 'red', 'motto' => null]]);
        $this->db->table('players')->insert([['id' => 1, 'team_id' => 3, 'name' => 'amir'], ['id' => 2, 'team_id' => 7, 'name' => 'hsn']]);

        $rebuilt = new Schema($this->db, $this->driver, [
            'tables' => [
                'teams' => static function (Blueprint $table): void {
                    $table->bigIncrements('id');
                    $table->string('name', 64)->unique();
                    $table->string('colour', 16)->default('grey');
                },
                'players' => self::players(...),
                'coaches' => static function (Blueprint $table): void {
                    $table->bigIncrements('id');
                    $table->string('name', 64);
                },
            ],
            'rows' => [
                'teams' => static fn(): array => [['name' => 'never']],
                'coaches' => static fn(): array => [['name' => 'head']],
            ],
        ]);

        self::assertSame([
            ['table' => 'teams', 'rows' => 2, 'added' => ['colour'], 'dropped' => ['motto']],
            ['table' => 'players', 'rows' => 2, 'added' => [], 'dropped' => []],
            ['table' => 'coaches', 'rows' => null, 'added' => [], 'dropped' => []],
        ], $rebuilt->rebuild());

        self::assertSame([['id' => 3, 'name' => 'blue', 'colour' => 'grey'], ['id' => 7, 'name' => 'red', 'colour' => 'grey']], $this->rows('teams'), 'the ids kept, a new column at its default, no starting rows in a table that had its own');
        self::assertSame(['head'], $this->db->table('coaches')->pluck('name')->all(), 'a table new to the database starts as a new shop\'s does');
        self::assertSame(['coaches', 'players', 'teams'], $this->tablesInDatabase(), 'nothing left aside');

        $builder = $this->db->getSchemaBuilder();
        self::assertTrue($builder->hasIndex('teams', ['name'], 'unique'));
        self::assertTrue($builder->hasIndex('players', ['name']));
        $this->db->table('teams')->where('id', 3)->delete();
        self::assertSame(['hsn'], $this->db->table('players')->pluck('name')->all(), 'the foreign key is back, cascading as it did');
        $this->expectException(QueryException::class);
        $this->db->table('players')->insert(['team_id' => 99, 'name' => 'ghost']);
    }

    public function testARebuildThatStopsLosesNothingAndTheNextOneFinishesItOnceTheSchemaIsFixed(): void
    {
        (new Schema($this->db, $this->driver, ['tables' => ['teams' => self::teams(...), 'players' => self::players(...)]]))->create();
        $this->db->table('teams')->insert([['id' => 1, 'name' => 'blue', 'motto' => 'go'], ['id' => 2, 'name' => 'blue', 'motto' => 'again']]);
        $this->db->table('players')->insert(['team_id' => 2, 'name' => 'amir']);

        $unique = new Schema($this->db, $this->driver, ['tables' => [
            'teams' => static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('name', 64)->unique();
            },
            'players' => self::players(...),
        ]]);
        try {
            $unique->rebuild();
            self::fail('two blue teams cannot take a unique name');
        } catch (QueryException $e) {
            self::assertStringContainsString('UNIQUE', $e->getMessage(), 'stopped with the database\'s own words');
        }
        self::assertSame(2, $this->db->table('teams' . Schema::ASIDE)->count(), 'the old rows wait aside');
        self::assertSame(1, $this->db->table('players' . Schema::ASIDE)->count());

        $fixed = new Schema($this->db, $this->driver, ['tables' => ['teams' => self::teams(...), 'players' => self::players(...)]]);
        $fixed->rebuild();

        self::assertSame(['go', 'again'], $this->db->table('teams')->orderBy('id')->pluck('motto')->all(), 'every row, through two rebuilds');
        self::assertSame(['amir'], $this->db->table('players')->pluck('name')->all());
        self::assertFalse($this->db->getSchemaBuilder()->hasIndex('teams', ['name'], 'unique'), 'the table made again as the schema says now');
        self::assertSame(['players', 'teams'], $this->tablesInDatabase());
    }

    public function testTheShopsOwnSchemaRebuildsOntoItselfKeepingEveryRow(): void
    {
        $schema = $this->shop();
        $schema->create();
        $this->db->table('users')->insert(['telegram_id' => 42, 'referral_code' => 'k7m2p9qa', 'created_at' => now(), 'updated_at' => now()]);
        $this->db->table('wallet_transactions')->insert(['user_id' => 1, 'type' => 'credit', 'amount' => '5000.00', 'balance_after' => '5000.00', 'created_at' => now()]);
        $this->db->table('settings')->insert(['key' => 'bot.enabled', 'value' => '1']);

        $report = $schema->rebuild();

        self::assertSame($schema->tables(), array_column($report, 'table'));
        self::assertSame([], array_merge(...array_column($report, 'added'), ...array_column($report, 'dropped')), 'the same shape');
        foreach (['users', 'wallet_transactions', 'settings', 'payment_methods'] as $table) {
            self::assertSame(1, $this->db->table($table)->count(), "{$table} — the wallet carried over, not made a second time");
        }
        $named = $schema->tables();
        sort($named);
        self::assertSame($named, $this->tablesInDatabase(), 'nothing left aside');
    }

    public function testTheCommandAsksFirstAndTellsWhatEachTableKept(): void
    {
        (new Schema($this->db, $this->driver, ['tables' => ['teams' => self::teams(...)]]))->create();
        $this->db->table('teams')->insert(['name' => 'blue']);
        $tester = new CommandTester(new DbRebuildCommand(new Schema($this->db, $this->driver, ['tables' => ['teams' => self::teams(...), 'players' => self::players(...)]])));

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Nothing done', $tester->getDisplay());
        self::assertSame(['teams'], $this->tablesInDatabase());

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertStringContainsString('teams: 1 row', $tester->getDisplay());
        self::assertStringContainsString('players: new table', $tester->getDisplay());
    }

    public function testTheCommandSaysWhereARebuildThatStoppedLeftTheRows(): void
    {
        (new Schema($this->db, $this->driver, ['tables' => ['teams' => self::teams(...)]]))->create();
        $this->db->table('teams')->insert([['name' => 'blue'], ['name' => 'blue']]);
        $tester = new CommandTester(new DbRebuildCommand(new Schema($this->db, $this->driver, ['tables' => ['teams' => static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name', 64)->unique();
        }]])));

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertStringContainsString('The rebuild stopped', $tester->getDisplay());
        self::assertStringContainsString('run db:rebuild again', $tester->getDisplay());
    }

    private function shop(): Schema
    {
        return new Schema($this->db, $this->driver, require dirname(__DIR__, 3) . '/database/schema.php');
    }

    /** @return array<string, \Closure(Blueprint): void> database/schema.php's tables */
    private function definitions(): array
    {
        return (require dirname(__DIR__, 3) . '/database/schema.php')['tables'];
    }

    private static function teams(Blueprint $table): void
    {
        $table->bigIncrements('id');
        $table->string('name', 64);
        $table->string('motto')->nullable();
    }

    private static function players(Blueprint $table): void
    {
        $table->bigIncrements('id');
        $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
        $table->string('name', 64);

        $table->index('name');
    }

    /** @return list<string> */
    private function tablesInDatabase(): array
    {
        $tables = $this->db->getSchemaBuilder()->getTableListing(schemaQualified: false);
        sort($tables);

        return $tables;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table): array
    {
        return array_map(static fn(object $row) => (array) $row, $this->db->table($table)->orderBy('id')->get()->all());
    }
}
