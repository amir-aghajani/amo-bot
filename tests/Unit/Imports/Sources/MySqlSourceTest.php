<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportSource;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Sources\MySqlDialect;
use App\Modules\Imports\Sources\Stager;
use App\Modules\Imports\Staging\Table;
use Psr\Log\LoggerInterface;

/**
 * The database source against a real MySQL or MariaDB server, through MySqlDialect: run only when AMOBOT_TEST_MYSQL
 * names one — `mysql://user:password@host:port/database`, a database the test may fill and empty, never a shop's (CI
 * has none, so it is skipped there). Its tables are made, staged in steps, the session found read-only, then dropped.
 */
final class MySqlSourceTest extends StagingTestCase
{
    private const USERS = 'amobot_import_test_user';
    private const PANELS = 'amobot_import_test_panel';

    /** @var array{host: string, port: int, database: string, username: string, password: string} */
    private array $connection;

    private \PDO $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('AMOBOT_TEST_MYSQL');
        $parts = is_string($url) && $url !== '' ? parse_url($url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'mysql') {
            self::markTestSkipped('AMOBOT_TEST_MYSQL names no MySQL database to test against.');
        }
        $this->connection = [
            'host' => (string) ($parts['host'] ?? '127.0.0.1'),
            'port' => (int) ($parts['port'] ?? 3306),
            'database' => ltrim((string) ($parts['path'] ?? ''), '/'),
            'username' => urldecode((string) ($parts['user'] ?? '')),
            'password' => urldecode((string) ($parts['pass'] ?? '')),
        ];
        $this->mysql = new \PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->connection['host'], $this->connection['port'], $this->connection['database']),
            $this->connection['username'],
            $this->connection['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->dropTables();
        $this->mysql->exec('CREATE TABLE `' . self::USERS . '` (`id` varchar(500) NOT NULL PRIMARY KEY, `username` varchar(500) NOT NULL DEFAULT \'\', `Balance` int NOT NULL DEFAULT 0, `step` varchar(50)) DEFAULT CHARSET=utf8mb4');
        $this->mysql->exec('CREATE TABLE `' . self::PANELS . '` (`name_panel` varchar(200), `password_panel` varchar(200)) DEFAULT CHARSET=utf8mb4');
        $user = $this->mysql->prepare('INSERT INTO `' . self::USERS . '` VALUES (?, ?, ?, ?)');
        for ($i = 1; $i <= 450; $i++) {
            $user->execute([(string) (1_000_000 + $i), "کاربر {$i}", $i % 4 === 0 ? -$i : $i * 1000, 'home']);
        }
        $this->mysql->exec('INSERT INTO `' . self::PANELS . "` VALUES ('DE', 'pa;ss'), ('NL', NULL)");
    }

    protected function tearDown(): void
    {
        if (isset($this->mysql)) {
            $this->dropTables();
        }

        parent::tearDown();
    }

    public function testStagesAMysqlDatabaseReadOnly(): void
    {
        $migrator = new TablesMigrator([
            self::USERS => new Table(['id', 'username', 'Balance', 'missing']),
            self::PANELS => new Table(['name_panel', 'password_panel'], ['password_panel']),
        ]);
        $stager = new Stager($this->db(), $this->service(Encrypter::class), $this->service(LoggerInterface::class), $this->folder);
        $import = $this->import(ImportSource::Database);
        $stager->keepConnection($import, $this->connection);

        $steps = 0;
        while (!$stager->stage($import, $migrator, Budget::checks(100))) {
            $steps++;
        }

        self::assertGreaterThan(3, $steps);
        $staged = $stager->staged($import, $migrator);
        $users = $staged->all(self::USERS);
        self::assertCount(450, $users);
        self::assertSame(['id' => '1000004', 'username' => 'کاربر 4', 'Balance' => '-4', 'missing' => null], $users[3]);
        self::assertSame([['name_panel' => 'DE', 'password_panel' => 'pa;ss'], ['name_panel' => 'NL', 'password_panel' => null]], $staged->all(self::PANELS));
        self::assertSame(['done' => 452, 'total' => 452], $stager->progress($import));

        // The session it reads through limits each statement (MySQL's in milliseconds, MariaDB's in seconds) and refuses
        // to write.
        $session = (new MySqlDialect())->connect($this->connection);
        $limits = $session->query("SHOW SESSION VARIABLES WHERE Variable_name IN ('max_execution_time', 'max_statement_time')");
        self::assertNotFalse($limits);
        $limits = array_column($limits->fetchAll(\PDO::FETCH_NUM), 1, 0);
        self::assertTrue(($limits['max_execution_time'] ?? null) === '20000' || (float) ($limits['max_statement_time'] ?? 0) === 20.0, (string) json_encode($limits));
        $this->expectException(\PDOException::class);
        $session->exec('INSERT INTO `' . self::PANELS . "` VALUES ('written', NULL)");
    }

    public function testWordsAWrongPasswordUnderTheAccount(): void
    {
        // As the container builds it: storage/imports, and MySQL.
        $stager = $this->service(Stager::class);

        $errors = $this->refusedWith(fn() => $stager->keepConnection($this->import(ImportSource::Database), ['password' => 'not-' . $this->connection['password']] + $this->connection));

        self::assertSame(['username', 'password'], array_keys($errors));
        self::assertStringStartsWith(ImportRefusedException::DENIED, $errors['password'][0]);
    }

    private function dropTables(): void
    {
        $this->mysql->exec('DROP TABLE IF EXISTS `' . self::USERS . '`, `' . self::PANELS . '`');
    }
}
