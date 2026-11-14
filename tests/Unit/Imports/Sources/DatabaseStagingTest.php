<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Staging\Table;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The other bot's database as the source, a SQLite file standing in for its MySQL: the connection tried at once and
 * kept encrypted, each field refused in the owner's words — MySQL's refusals too, never with the password —; the
 * tables the migrator reads staged under its names (whatever their case), only the columns it reads, by primary key or
 * by place, every row once however the steps fall; a connection lost while reading refused under `connection`; the
 * database never written.
 */
final class DatabaseStagingTest extends StagingTestCase
{
    private const PASSWORD = 'hunter2!';

    /** The other bot's database. */
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = $this->scratchDir() . '/vpnbot.sqlite';
        $pdo = $this->other();
        $pdo->exec('CREATE TABLE "user" ("id" TEXT PRIMARY KEY, "username" TEXT, "number" TEXT, "Balance" INTEGER, "User_Status" TEXT, "agent" TEXT, "register" TEXT, "description_blocking" TEXT, "step" TEXT)');
        $pdo->exec('CREATE TABLE "MARZBAN_PANEL" ("id" INTEGER PRIMARY KEY, "NAME_PANEL" TEXT, "url_panel" TEXT, "username_panel" TEXT, "password_panel" TEXT, "api_key" TEXT, "type" TEXT)');
        $pdo->exec('CREATE TABLE "Payment_report" ("id" INTEGER, "price" TEXT)');
        $pdo->exec('CREATE TABLE "setting" ("Bot_Status" TEXT, "wheelـluck" TEXT)');
        $pdo->exec('CREATE TABLE "textbot" ("id_text" TEXT, "text" TEXT)');
        $pdo->beginTransaction();
        $user = $pdo->prepare('INSERT INTO "user" VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($this->users() as $row) {
            $user->execute([...array_values($row), 'home']);
        }
        $pdo->exec("INSERT INTO \"MARZBAN_PANEL\" VALUES (1, 'آلمان', 'https://de.example.com', 'admin', 'pa;ss', NULL, 'x-ui_single'), (2, 'NL', 'https://nl.example.com', NULL, NULL, 'tok', 'pasarguard')");
        $payment = $pdo->prepare('INSERT INTO "Payment_report" VALUES (?, ?)');
        for ($i = 610; $i >= 1; $i--) {
            $payment->execute([$i, (string) ($i * 1000)]);
        }
        $pdo->exec("INSERT INTO \"setting\" VALUES ('botstatuson', '1')");
        $pdo->exec("INSERT INTO \"textbot\" VALUES ('a', 'b'), ('c', 'd'), ('e', 'f')");
        $pdo->commit();
    }

    public function testKeepsAConnectionItCouldOpenEncrypted(): void
    {
        $import = $this->import(ImportSource::Database);

        $this->stager()->keepConnection($import, $this->connection());

        $kept = Import::query()->findOrFail($import->id);
        self::assertStringNotContainsString(self::PASSWORD, (string) $kept->connection);
        self::assertSame(['host' => 'db.example.com', 'port' => 3306, 'database' => $this->database, 'username' => 'bot', 'password' => self::PASSWORD], json_decode($this->service(Encrypter::class)->decrypt((string) $kept->connection), true));
        self::assertSame(['host' => 'db.example.com', 'port' => 3306, 'database' => $this->database, 'username' => 'bot', 'password' => self::PASSWORD], $this->dialect->connections[0]);
        self::assertSame(['MARZBAN_PANEL', 'Payment_report', 'setting', 'textbot', 'user'], array_keys($this->stager()->schema($kept)->tables()));
        self::assertSame(['done' => 0, 'total' => null], $this->stager()->progress($kept));
    }

    public function testRefusesEachFieldItLacksBeforeTryingIt(): void
    {
        $refused = fn(array $input): array => $this->refusedWith(fn() => $this->stager()->keepConnection($this->import(ImportSource::Database), $input));

        self::assertSame([
            'host' => [ImportRefusedException::HOST_MISSING],
            'database' => [ImportRefusedException::DATABASE_MISSING],
            'username' => [ImportRefusedException::USERNAME_MISSING],
        ], $refused([]));
        self::assertSame(['port' => [ImportRefusedException::PORT_INVALID]], $refused(['port' => 'abc'] + $this->connection()));
        self::assertSame(['port' => [ImportRefusedException::PORT_INVALID]], $refused(['port' => 70000] + $this->connection()));
        self::assertSame(['host' => [ImportRefusedException::HOST_INVALID]], $refused(['host' => 'db;unix_socket=/tmp/mysql.sock'] + $this->connection()));
        self::assertSame(['host' => [ImportRefusedException::HOST_INVALID]], $refused(['host' => 'mysql://db.example.com'] + $this->connection()));
        self::assertSame([], $this->dialect->connections);
    }

    /** @return array<string, array{\PDOException, list<string>, string}> */
    public static function refusals(): array
    {
        return [
            'a wrong password' => [new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'bot'@'203.0.113.7' (using password: YES)", 1045), ['username', 'password'], ImportRefusedException::DENIED],
            'an account of the socket alone' => [new \PDOException("SQLSTATE[HY000] [1698] Access denied for user 'root'@'localhost'", 1698), ['username', 'password'], ImportRefusedException::DENIED],
            'no rights to the database' => [new \PDOException("SQLSTATE[HY000] [1044] Access denied for user 'bot'@'%' to database 'vpnbot'", 1044), ['database'], ImportRefusedException::NO_ACCESS],
            'no such database' => [new \PDOException("SQLSTATE[HY000] [1049] Unknown database 'vpnbot'", 1049), ['database'], ImportRefusedException::UNKNOWN_DATABASE],
            'a closed port' => [new \PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002), ['host'], ImportRefusedException::UNREACHABLE],
            'no such host' => [new \PDOException("SQLSTATE[HY000] [2005] Unknown MySQL server host 'db.example.com' (11001)", 2005), ['host'], ImportRefusedException::UNREACHABLE],
            'a number in the words alone' => [new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'bot'@'localhost' (using password: YES)"), ['username', 'password'], ImportRefusedException::DENIED],
        ];
    }

    /** @param list<string> $fields */
    #[DataProvider('refusals')]
    public function testWordsTheServersRefusalUnderItsField(\PDOException $refusal, array $fields, string $lead): void
    {
        $this->dialect->refusal = $refusal;

        $errors = $this->refusedWith(fn() => $this->stager()->keepConnection($this->import(ImportSource::Database), $this->connection()));

        self::assertSame($fields, array_keys($errors));
        foreach ($errors as $messages) {
            self::assertSame($lead . ' (' . $refusal->getMessage() . ')', $messages[0]);
            self::assertStringNotContainsString(self::PASSWORD, $messages[0]);
        }
    }

    public function testSaysWhenTheHostCannotSpeakMysql(): void
    {
        $this->dialect->refusal = new \PDOException('could not find driver');

        self::assertSame(['host' => [ImportRefusedException::NO_MYSQL]], $this->refusedWith(fn() => $this->stager()->keepConnection($this->import(ImportSource::Database), $this->connection())));
    }

    public function testRefusesADatabaseWithoutTables(): void
    {
        $empty = $this->scratchDir() . '/empty.sqlite';
        touch($empty);

        self::assertSame(['database' => [ImportRefusedException::EMPTY_DATABASE]], $this->refusedWith(fn() => $this->stager()->keepConnection($this->import(ImportSource::Database), ['database' => $empty] + $this->connection())));
    }

    public function testStagesTheTablesTheMigratorReads(): void
    {
        $import = $this->keptConnection();

        self::assertTrue($this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));

        $staged = $this->staged($import);
        self::assertSame(['user', 'marzban_panel', 'Payment_report', 'setting'], array_keys($staged));
        self::assertSame($this->users(), $staged['user']);
        self::assertSame([
            ['name_panel' => 'آلمان', 'url_panel' => 'https://de.example.com', 'username_panel' => 'admin', 'password_panel' => 'pa;ss', 'api_key' => null, 'type' => 'x-ui_single', 'secret_code' => null],
            ['name_panel' => 'NL', 'url_panel' => 'https://nl.example.com', 'username_panel' => null, 'password_panel' => null, 'api_key' => 'tok', 'type' => 'pasarguard', 'secret_code' => null],
        ], $staged['marzban_panel']);
        // No primary key: by place, as the table holds them.
        self::assertSame(array_map(static fn(int $i): array => ['id' => (string) $i], range(610, 1)), $staged['Payment_report']);
        self::assertSame([['Bot_Status' => 'botstatuson', 'wheelـluck' => '1']], $staged['setting']);
        self::assertSame(['id', 'name_panel', 'url_panel', 'username_panel', 'password_panel', 'api_key', 'type'], $this->stager()->schema($import)->columns('marzban_panel'));
        self::assertSame(['done' => $this->total(), 'total' => $this->total()], $this->stager()->progress($import));
    }

    public function testKeepsTheSecretsEncryptedAtRest(): void
    {
        $import = $this->keptConnection();
        $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited());

        $panel = $this->atRest($import)['marzban_panel'][0];
        self::assertNotSame('pa;ss', $panel['password_panel']);
        self::assertSame('pa;ss', $this->service(Encrypter::class)->decrypt((string) $panel['password_panel']));
        self::assertSame('admin', $panel['username_panel']);
    }

    public function testStagesEachRowOnceHoweverTheStepsFall(): void
    {
        $whole = $this->keptConnection();
        $this->stager()->stage($whole, TablesMigrator::bot(), Budget::unlimited());
        $expected = $this->staged($whole);

        foreach ([1, 298, 299, 300, 5000] as $checks) {
            $import = $this->keptConnection();
            $steps = $this->stageInSteps($import, $checks);

            self::assertSame($expected, $this->staged($import), "Steps of {$checks} checks.");
            self::assertSame($this->total(), $this->db()->table('import_rows')->where('import_id', $import->id)->count());
            self::assertGreaterThan($checks < $this->total() ? 1 : 0, $steps);
        }
    }

    public function testStagesTheRowsOfATableWithNoneOfTheColumnsItReads(): void
    {
        $migrator = new TablesMigrator(['textbot' => new Table(['no_such_column'])]);
        $import = $this->keptConnection();

        self::assertTrue($this->stager()->stage($import, $migrator, Budget::unlimited()));
        self::assertSame(['textbot' => array_fill(0, 3, ['no_such_column' => null])], $this->staged($import, $migrator));
    }

    public function testRefusesToReadOnWhenTheConnectionFails(): void
    {
        $import = $this->keptConnection();
        $this->dialect->refusal = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'bot'@'203.0.113.7' (using password: YES)", 1045);

        $errors = $this->refusedWith(fn() => $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));

        self::assertSame(['connection'], array_keys($errors));
        self::assertStringStartsWith(ImportRefusedException::DENIED, $errors['connection'][0]);
    }

    public function testRefusesToReadOnWhenATableGoesAway(): void
    {
        $import = $this->keptConnection();
        self::assertFalse($this->stager()->stage($import, TablesMigrator::bot(), Budget::checks(10)));
        $this->other()->exec('DROP TABLE "user"');

        $errors = $this->refusedWith(fn() => $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));

        self::assertSame(['connection'], array_keys($errors));
        self::assertStringStartsWith('خواندن دیتابیس ربات قبلی نیمه‌کاره ماند (', $errors['connection'][0]);
    }

    public function testNeverWritesToTheOtherDatabase(): void
    {
        $before = md5_file($this->database);
        $import = $this->keptConnection();
        $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited());

        self::assertSame($before, md5_file($this->database));
    }

    public function testForgetsTheConnectionOnceTheReadingIsDoneNotTheRows(): void
    {
        $import = $this->keptConnection();
        $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited());

        $this->stager()->readingDone($import);

        $done = Import::query()->findOrFail($import->id);
        self::assertNull($done->connection);
        self::assertCount(count($this->users()), $this->staged($done)['user'] ?? []);
        self::assertTrue($this->stager()->stage($done, TablesMigrator::bot(), Budget::unlimited()));
        self::assertSame(['done' => $this->total(), 'total' => $this->total()], $this->stager()->progress($done));
    }

    public function testDiscardForgetsTheConnectionAndTheRows(): void
    {
        $import = $this->keptConnection();
        $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited());

        $this->stager()->discard($import);

        self::assertNull(Import::query()->findOrFail($import->id)->connection);
        self::assertSame([], $this->staged($import));
    }

    public function testRefusesToReadOnOnceTheConnectionIsGone(): void
    {
        $import = $this->keptConnection();
        $this->stager()->discard($import);

        self::assertSame(['connection' => [ImportRefusedException::GONE]], $this->refusedWith(fn() => $this->stager()->stage(Import::query()->findOrFail($import->id), TablesMigrator::bot(), Budget::unlimited())));
    }

    /** Every row the tables the migrator reads hold: the customers, two panels, 610 payments and the settings' row. */
    private function total(): int
    {
        return count($this->users()) + 2 + 610 + 1;
    }

    /**
     * The customers of the other bot, as the migrator reads them, in their keys' order: text, so "100" before "99".
     *
     * @return list<array<string, ?string>>
     */
    private function users(): array
    {
        $users = [];
        foreach (array_merge(range(1, 640), [99_000, 100_000]) as $i) {
            $users[] = ['id' => (string) (1_000_000 + $i), 'username' => "user_{$i}", 'number' => $i % 3 === 0 ? 'none' : "98912{$i}", 'Balance' => (string) ($i % 7 === 0 ? -$i * 100 : $i * 1000), 'User_Status' => $i % 11 === 0 ? 'block' : 'Active', 'agent' => 'f', 'register' => (string) (1_700_000_000 + $i), 'description_blocking' => $i % 5 === 0 ? null : "یادداشت {$i}"];
        }
        foreach (['99', '100', '7', 'abc', 'Z'] as $id) {
            $users[] = ['id' => $id, 'username' => '', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'n', 'register' => 'none', 'description_blocking' => null];
        }
        usort($users, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $users;
    }

    /** @return array<string, string> */
    private function connection(): array
    {
        return ['host' => 'db.example.com', 'port' => '3306', 'database' => $this->database, 'username' => 'bot', 'password' => self::PASSWORD];
    }

    private function keptConnection(): Import
    {
        $import = $this->import(ImportSource::Database);
        $this->stager()->keepConnection($import, $this->connection());

        return $import;
    }

    /** The other bot's database, as its own bot writes it. */
    private function other(): \PDO
    {
        return new \PDO('sqlite:' . $this->database, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
}
