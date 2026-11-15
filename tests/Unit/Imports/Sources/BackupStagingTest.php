<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Sources;

use App\Core\Security\Encrypter;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Sources\MySqlDialect;
use App\Modules\Imports\Sources\RowWriter;
use App\Modules\Imports\Sources\Stager;
use App\Modules\Imports\Staging\Table;
use App\Modules\Telegram\Messages;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A backup file staged: mysqldump's, Faoxima's own PHP writer's and phpMyAdmin's dumps, uploaded plain, zipped (as
 * Faoxima sends it) or gzipped and told by their bytes; only the tables and columns the migrator reads, under its
 * names whatever their case, secrets encrypted at rest; every row staged once however the steps fall, even after a
 * step that died; what is no dump, a broken one or one behind a password, refused under `file`; the import's files and
 * rows discarded.
 */
final class BackupStagingTest extends StagingTestCase
{
    /** The customers of the mysqldump fixture, as the migrator reads them. */
    private const USERS = [
        ['id' => '1000001', 'username' => 'ali_vpn', 'number' => '+989121234567', 'Balance' => '150000', 'User_Status' => 'Active', 'agent' => 'f', 'register' => '1700000000', 'description_blocking' => null],
        ['id' => '1000002', 'username' => '', 'number' => 'none', 'Balance' => '-25000', 'User_Status' => 'block', 'agent' => 'n2', 'register' => 'none', 'description_blocking' => "بدهکار؛ \"مسدود\" شد\r\nخط دوم\ttab \\ backslash 100\\% (و پرانتز), یک 'نقل'"],
        ['id' => '1000003', 'username' => 'نیما_۱', 'number' => '989351112233', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'n', 'register' => '1700000500', 'description_blocking' => ''],
        ['id' => '1000004', 'username' => 'zahra', 'number' => 'confirm number by admin', 'Balance' => '2500', 'User_Status' => 'Active', 'agent' => 'f', 'register' => 'none', 'description_blocking' => null],
    ];

    public function testStagesAMysqldumpBackup(): void
    {
        $import = $this->stagedBackup(self::fixture('mysqldump.sql'));

        self::assertSame([
            'user' => self::USERS,
            'marzban_panel' => [
                ['name_panel' => 'آلمان ۱', 'url_panel' => 'https://de.example.com:2053', 'username_panel' => 'admin', 'password_panel' => "p@ss;word's", 'api_key' => null, 'type' => 'x-ui_single', 'secret_code' => "k\0\x1A"],
                ['name_panel' => 'Netherlands', 'url_panel' => 'https://nl.example.com', 'username_panel' => 'root', 'password_panel' => '', 'api_key' => 'tok-123', 'type' => 'marzban', 'secret_code' => null],
            ],
        ], $this->staged($import));
        self::assertSame([
            'marzban_panel' => ['id', 'code_panel', 'name_panel', 'url_panel', 'username_panel', 'password_panel', 'api_key', 'type', 'hosts', 'secret_code'],
            'textbot' => ['id_text', 'text'],
            'user' => ['id', 'limit_usertest', 'username', 'number', 'Balance', 'User_Status', 'agent', 'register', 'description_blocking'],
        ], $this->stager()->schema($import)->tables());
        $size = strlen(self::fixture('mysqldump.sql'));
        self::assertSame(['done' => $size, 'total' => $size], $this->stager()->progress($import));
        self::assertSame('backup.sql', $import->file_name);
    }

    public function testStagesABackupOfFaoximasOwnPhpWriter(): void
    {
        $import = $this->stagedBackup(self::fixture('faoxima-php.sql'));

        self::assertSame([
            'user' => [
                ['id' => '1000001', 'username' => 'reza', 'number' => '989121112233', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'f', 'register' => '1700000000', 'description_blocking' => null],
                ['id' => '1000002', 'username' => 'sara', 'number' => 'none', 'Balance' => '-5000', 'User_Status' => 'block', 'agent' => 'n', 'register' => 'none', 'description_blocking' => "line one\nline two\r\nwith \\ a backslash, a 'quote' and \"double\", a nul \0 and a sub \x1A"],
                ['id' => '1000003', 'username' => 'مریم', 'number' => 'confrim number by admin', 'Balance' => '1200000', 'User_Status' => 'Active', 'agent' => 'n2', 'register' => '1700003600', 'description_blocking' => ''],
            ],
            'Payment_report' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
            'setting' => [['Bot_Status' => 'botstatuson', 'wheelـluck' => '1', 'wheelـluck_price' => '0', 'keyboardmain' => '{"keyboard":[[{"text":"text_sell"},{"text":"text_extend"}]]}']],
            'DiscountSell' => [
                ['codeDiscount' => 'NOWRUZ', 'price' => '20', 'limitDiscount' => '100', 'usedDiscount' => '7', 'time' => null],
                ['codeDiscount' => "off'50", 'price' => '50', 'limitDiscount' => '0', 'usedDiscount' => '0', 'time' => null],
            ],
        ], $this->staged($import));
        self::assertSame(['DiscountSell', 'Payment_report', 'setting', 'user'], array_keys($this->stager()->schema($import)->tables()));
        self::assertTrue($this->stager()->schema($import)->hasColumns('setting', 'wheelـluck'));
    }

    public function testStagesAPhpMyAdminExport(): void
    {
        $import = $this->stagedBackup(self::fixture('phpmyadmin.sql'), 'mybot_db.sql');

        self::assertSame([
            'user' => [
                ['id' => '1000001', 'username' => 'ali_vpn', 'number' => '+989121234567', 'Balance' => '150000', 'User_Status' => 'Active', 'agent' => 'f', 'register' => '1700000000', 'description_blocking' => null],
                ['id' => '1000002', 'username' => '', 'number' => 'none', 'Balance' => '-25000', 'User_Status' => 'block', 'agent' => 'n2', 'register' => 'none', 'description_blocking' => "It's blocked"],
                ['id' => '1000003', 'username' => 'نیما', 'number' => 'none', 'Balance' => '0', 'User_Status' => 'Active', 'agent' => 'n', 'register' => '1700000500', 'description_blocking' => null],
            ],
            'marzban_panel' => [
                ['name_panel' => 'Germany', 'url_panel' => 'https://de.example.com:8000', 'username_panel' => 'admin', 'password_panel' => 'secret#1', 'api_key' => null, 'type' => 'marzban', 'secret_code' => 'key'],
                ['name_panel' => 'Finland', 'url_panel' => 'https://fi.example.com/panel', 'username_panel' => null, 'password_panel' => null, 'api_key' => 'api-key-xyz', 'type' => 'pasarguard', 'secret_code' => ''],
            ],
            'channels' => [['link' => '@my_channel', 'remark' => 'کانال اصلی'], ['link' => '-1001234567890', 'remark' => "It's private"]],
        ], $this->staged($import));
    }

    public function testIsBuiltByTheContainerSpeakingMysql(): void
    {
        $stager = $this->service(Stager::class);

        self::assertInstanceOf(MySqlDialect::class, (new \ReflectionProperty($stager, 'dialect'))->getValue($stager));
    }

    public function testKeepsTheSecretsEncryptedAtRest(): void
    {
        $import = $this->stagedBackup(self::fixture('mysqldump.sql'));

        $panel = $this->atRest($import)['marzban_panel'][0];
        self::assertNotSame("p@ss;word's", $panel['password_panel']);
        self::assertSame("p@ss;word's", $this->service(Encrypter::class)->decrypt((string) $panel['password_panel']));
        self::assertNull($panel['api_key']);
        self::assertSame('admin', $panel['username_panel']);
        self::assertSame(['marzban_panel', 'user'], array_keys($this->atRest($import)), 'Only the tables the migrator reads.');
        self::assertStringNotContainsString('tok-123', (string) $this->db()->table('import_rows')->where('import_id', $import->id)->pluck('data')->implode(' '));
    }

    public function testTakesFaoximasZipItsDumpGzippedOrPlainByTheirBytes(): void
    {
        $dump = self::fixture('mysqldump.sql');
        // Faoxima's backup.zip: backup.sql at its root, the bot's files (a larger .sql among them) beside it.
        $faoxima = $this->zip(['vpnbot/config.php' => '<?php $x = 1;', 'vpnbot/Marzban/big.sql' => str_repeat('-- x', 50_000), 'backup.sql' => $dump]);
        // An export zipped by hand: no backup.sql, its largest .sql the dump.
        $exported = $this->zip(['notes/readme.sql' => '-- nothing', 'vpnbot_2026.sql' => $dump, '__MACOSX/._vpnbot_2026.sql' => str_repeat('x', 200_000)]);

        foreach ([[$dump, 'backup.sql', 'sql'], [$faoxima, 'backup.zip', 'zip'], [$exported, 'export.zip', 'zip'], [(string) gzencode($dump), 'backup.sql.gz', 'gz'], [$faoxima, 'backup.sql', 'zip']] as [$bytes, $name, $kind]) {
            $import = $this->import();
            $this->stager()->keepUpload($import, self::upload($bytes, $name));
            self::assertSame($kind, $import->reading['kind'] ?? null, $name);
            self::assertSame($kind === 'sql' ? strlen($dump) : null, $this->stager()->progress($import)['total']);

            self::assertTrue($this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));
            self::assertSame(self::USERS, $this->staged($import)['user'] ?? null, $name);
            self::assertSame(['dump.sql'], array_map(basename(...), glob($this->folder . '/' . $import->id . '/*') ?: []), 'The upload goes once unpacked.');
        }
    }

    /** @return array<string, array{\Closure(self): array{string, string}, string}> */
    public static function notDumps(): array
    {
        return [
            'a picture' => [static fn(self $test): array => ["\x89PNG\r\n\x1A\n\0\0\0\rIHDR" . random_bytes(64), 'backup.sql'], ImportRefusedException::NOT_A_DUMP],
            'words' => [static fn(self $test): array => ["Dear owner,\nhere is the backup.", 'backup.sql'], ImportRefusedException::NOT_A_DUMP],
            "AmoBot's own backup" => [static fn(self $test): array => [(string) gzencode('{"format":"amobot-backup","format_version":1}'), 'amobot-backup.json.gz'], ImportRefusedException::NOT_A_DUMP],
            'an empty file' => [static fn(self $test): array => ['', 'backup.sql'], ImportRefusedException::NOT_A_DUMP],
            'a zip without a dump' => [static fn(self $test): array => [$test->zip(['vpnbot/config.php' => '<?php']), 'backup.zip'], ImportRefusedException::NO_SQL_IN_ZIP],
            'a zip whose .sql is no dump' => [static fn(self $test): array => [$test->zip(['backup.sql' => 'hello there']), 'backup.zip'], ImportRefusedException::NOT_A_DUMP],
            'a zip cut short' => [static fn(self $test): array => [substr($test->zip(['backup.sql' => self::fixture('mysqldump.sql')]), 0, 600), 'backup.zip'], ImportRefusedException::BROKEN],
        ];
    }

    /** @param \Closure(self): array{string, string} $file */
    #[DataProvider('notDumps')]
    public function testRefusesWhatIsNoDump(\Closure $file, string $refusal): void
    {
        [$bytes, $name] = $file($this);
        $import = $this->import();

        self::assertSame(['file' => [$refusal]], $this->refusedWith(fn() => $this->stager()->keepUpload($import, self::upload($bytes, $name))));
        self::assertDirectoryDoesNotExist($this->folder . '/' . $import->id);
        self::assertNull($import->reading);
    }

    public function testRefusesAZipWhoseDumpIsBehindAPassword(): void
    {
        if (!\ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256, true)) {
            self::markTestSkipped("This host's libzip cannot write an AES-encrypted entry.");
        }
        // MirzaBot's own backup before 0.1.6: backup_<date>.sql alone in a zip, AES-256 behind a password.
        $path = $this->scratchDir() . '/backup_2026-05-20.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE));
        $zip->addFromString('backup_2026-05-20.sql', self::fixture('mysqldump.sql'));
        self::assertTrue($zip->setEncryptionName('backup_2026-05-20.sql', \ZipArchive::EM_AES_256, 'not-the-password'));
        $zip->close();
        $import = $this->import();

        self::assertSame(['file' => [ImportRefusedException::ENCRYPTED_ZIP]], $this->refusedWith(fn() => $this->stager()->keepUpload($import, self::upload((string) file_get_contents($path), 'backup_2026-05-20.zip'))), 'the owner opens it: the shop never guesses');
        self::assertDirectoryDoesNotExist($this->folder . '/' . $import->id);
        self::assertNull($import->reading);
    }

    public function testRefusesAnUploadThatDidNotArrive(): void
    {
        $refused = fn(?int $error): array => $this->refusedWith(fn() => $this->stager()->keepUpload($this->import(), $error === null ? null : self::upload('x', 'backup.sql', $error)));

        self::assertSame(['file' => [ImportRefusedException::NO_FILE]], $refused(null));
        self::assertSame(['file' => [ImportRefusedException::NO_FILE]], $refused(UPLOAD_ERR_NO_FILE));
        self::assertSame(['file' => [ImportRefusedException::BROKEN]], $refused(UPLOAD_ERR_PARTIAL));
        self::assertStringStartsWith('فایل بزرگ‌تر از چیزی است که هاست می‌پذیرد (', $refused(UPLOAD_ERR_INI_SIZE)['file'][0]);
    }

    public function testRefusesADumpTheFileEndsInsideOf(): void
    {
        $dump = self::fixture('mysqldump.sql');
        $cut = substr($dump, 0, (int) strpos($dump, "('1000003'") + 5);
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload($cut));

        self::assertSame(['file' => [ImportRefusedException::BROKEN]], $this->refusedWith(fn() => $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited())));
    }

    public function testRefusesAFileOfCommentsAlone(): void
    {
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload("-- MySQL dump 10.13\n--\n-- nothing else\n"));

        self::assertSame(['file' => [ImportRefusedException::NOT_A_DUMP]], $this->refusedWith(fn() => $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited())));
    }

    public function testRefusesToReadOnWhenTheFileIsGone(): void
    {
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload((string) gzencode(self::fixture('mysqldump.sql')), 'backup.gz'));
        unlink($this->folder . '/' . $import->id . '/upload');

        self::assertSame(['file' => [ImportRefusedException::GONE]], $this->refusedWith(fn() => $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited())));
    }

    public function testStagesEachRowOnceHoweverTheStepsFall(): void
    {
        $dump = self::manyRows();
        $whole = $this->stagedBackup($dump);
        $expected = $this->staged($whole);
        self::assertCount(750, $expected['user']);
        self::assertCount(2, $expected['marzban_panel']);

        foreach ([0, 1, 6, 298, 299, 300, 1000] as $checks) {
            $import = $this->import();
            $this->stager()->keepUpload($import, self::upload($dump));
            $steps = $this->stageInSteps($import, $checks);

            self::assertSame($expected, $this->staged($import), "Steps of {$checks} checks.");
            self::assertSame(752, $this->db()->table('import_rows')->where('import_id', $import->id)->count());
            self::assertGreaterThan($checks < 700 ? 1 : 0, $steps);
        }
    }

    public function testABatchIsFullByItsBytesAsWellAsItsRows(): void
    {
        $rows = new RowWriter($this->db(), $this->service(Encrypter::class), $this->import(), []);
        $rows->add('user', ['id' => '1', 'note' => str_repeat('x', intdiv(RowWriter::BATCH_BYTES, 2))]);
        self::assertFalse($rows->full());

        $rows->add('user', ['id' => '2', 'note' => str_repeat('x', intdiv(RowWriter::BATCH_BYTES, 2))]);
        self::assertTrue($rows->full(), 'two large rows fill it');

        $rows->flush([]);
        self::assertFalse($rows->full());
    }

    public function testGoesOnFarIntoADumpOfManyPieces(): void
    {
        $dump = self::longRows();
        self::assertGreaterThan(3 * 1_048_576, strlen($dump));
        $expected = $this->staged($this->stagedBackup($dump));
        self::assertCount(360, $expected['user']);

        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload((string) gzencode($dump), 'backup.sql.gz'));

        self::assertGreaterThan(10, $this->stageInSteps($import, 23));
        self::assertSame($expected, $this->staged($import));
    }

    public function testStagesEachRowOnceAfterAStepThatDied(): void
    {
        $dump = self::manyRows();
        $expected = $this->staged($this->stagedBackup($dump));
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload($dump));

        // The process dies as it saves its second batch: that batch and where it got to are rolled back together.
        $saves = 0;
        $died = false;
        try {
            $this->whileListening('eloquent.saving: ' . Import::class, static function () use (&$saves): void {
                if (++$saves === 2) {
                    throw new \RuntimeException('killed');
                }
            }, fn() => $this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));
        } catch (\RuntimeException $e) {
            $died = $e->getMessage() === 'killed';
        }
        self::assertTrue($died);
        self::assertSame(300, $this->db()->table('import_rows')->where('import_id', $import->id)->count());

        $this->stageInSteps($import, 1000);
        self::assertSame($expected, $this->staged($import));
        self::assertSame(752, $this->db()->table('import_rows')->where('import_id', $import->id)->count());
    }

    public function testCountsItsProgressInBytes(): void
    {
        $dump = self::manyRows();
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload($this->zip(['backup.sql' => $dump]), 'backup.zip'));
        self::assertSame(['done' => 0, 'total' => null], $this->stager()->progress($import));

        self::assertFalse($this->stager()->stage($import, TablesMigrator::bot(), Budget::checks(200)));
        $progress = $this->stager()->progress($import);
        self::assertSame(strlen($dump), $progress['total']);
        // Inside the 700 rows' INSERT: it goes on from the statement's start, and shows the bytes read past it.
        $insert = (int) strpos($dump, 'INSERT INTO');
        self::assertSame($insert, $import->reading['offset'] ?? null);
        self::assertGreaterThan($insert + 1000, $progress['done']);
        self::assertLessThan(strlen($dump), $progress['done']);
        self::assertFalse($this->stager()->stage($import, TablesMigrator::bot(), Budget::checks(200)));
        self::assertSame($insert, $import->reading['offset'] ?? null);
        self::assertGreaterThan($progress['done'], $this->stager()->progress($import)['done']);

        self::assertTrue($this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));
        self::assertSame(['done' => strlen($dump), 'total' => strlen($dump)], $this->stager()->progress($import));
        self::assertTrue($this->stager()->stage($import, TablesMigrator::bot(), Budget::checks(0)), 'A reading done is done.');
    }

    public function testFindsTheTablesAndColumnsWhateverTheirCase(): void
    {
        $import = $this->stagedBackup(<<<'SQL'
            CREATE TABLE `USER` (`ID` varchar(20), `UserName` varchar(50), `balance` int, `Other` text);
            INSERT INTO `USER` VALUES ('1','a',5,'o');
            CREATE TABLE `payment_report` (`ID` int, `price` int);
            INSERT INTO `payment_report` VALUES (1, 100), (2, 200);
            INSERT INTO `Setting` (`Bot_Status`) VALUES ('on');
            SQL);

        self::assertSame([
            'user' => [['id' => '1', 'username' => 'a', 'number' => null, 'Balance' => '5', 'User_Status' => null, 'agent' => null, 'register' => null, 'description_blocking' => null]],
            'Payment_report' => [['id' => '1'], ['id' => '2']],
            'setting' => [['Bot_Status' => 'on']],
        ], $this->staged($import));
        self::assertSame(['user' => ['id', 'username', 'Balance', 'Other'], 'Payment_report' => ['id', 'price'], 'setting' => ['Bot_Status']], $this->stager()->schema($import)->tables());
    }

    public function testPassesOverRowsItCannotMapAndSaysSo(): void
    {
        $import = $this->stagedBackup(<<<'SQL'
            INSERT INTO `channels` VALUES ('@a','b');
            INSERT INTO `textbot` VALUES ('c','d');
            INSERT INTO `DiscountSell` (`codeDiscount`, `price`) VALUES ('X', '5', 'one too many');
            INSERT INTO `user` (`id`, `username`, `Balance`) VALUES ('7', 'short');
            SQL);

        self::assertSame([
            'user' => [['id' => '7', 'username' => 'short', 'number' => null, 'Balance' => null, 'User_Status' => null, 'agent' => null, 'register' => null, 'description_blocking' => null]],
            'DiscountSell' => [['codeDiscount' => 'X', 'price' => '5', 'limitDiscount' => null, 'usedDiscount' => null, 'time' => null]],
        ], $this->staged($import));
        self::assertSame(['channels'], $import->reading['unmapped'] ?? null);
        self::assertSame(['DiscountSell' => ['codeDiscount', 'price'], 'user' => ['id', 'username', 'Balance']], $this->stager()->schema($import)->tables());
    }

    public function testLogsTheRowsItPassesOver(): void
    {
        $logs = $this->logs();
        $this->stagedBackup("INSERT INTO `channels` VALUES ('@a','b');");

        self::assertTrue($logs->hasWarningThatPasses(static fn(LogRecord $record): bool => str_contains($record->message, 'passed over') && ($record->context['table'] ?? null) === 'channels'));
    }

    public function testReplacesTextThatIsNotUtf8(): void
    {
        $import = $this->stagedBackup("CREATE TABLE `user` (`id` text, `username` text, `bad\xFFname` text);\nINSERT INTO `user` VALUES ('1', 'bad \xFF\xFE end', 'x');");

        $username = (string) ($this->staged($import)['user'][0]['username'] ?? '');
        self::assertTrue(mb_check_encoding($username, 'UTF-8'));
        self::assertSame('bad ?? end', $username);
        self::assertTrue(mb_check_encoding(implode(',', $this->stager()->schema($import)->columns('user')), 'UTF-8'));
    }

    public function testReadsCrlfLinesAndAByteOrderMark(): void
    {
        $import = $this->stagedBackup("\u{FEFF}" . str_replace("\n", "\r\n", self::fixture('mysqldump.sql')));

        self::assertSame(self::USERS, $this->staged($import)['user'] ?? null);
    }

    public function testStagesEveryColumnOfATableItReadsWhole(): void
    {
        $migrator = new TablesMigrator(['setting' => new Table(secrets: ['token'])]);
        $import = $this->stagedBackup("CREATE TABLE `setting` (`a` int, `token` text, `b` text);\nINSERT INTO `setting` VALUES (1, 'secret', NULL);", migrator: $migrator);

        self::assertSame(['setting' => [['a' => '1', 'token' => 'secret', 'b' => null]]], $this->staged($import, $migrator));
        self::assertNotSame('secret', $this->atRest($import)['setting'][0]['token']);
    }

    public function testKeepsTheNameTheFileWasUploadedUnder(): void
    {
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload(self::fixture('mysqldump.sql'), 'C:\\fakepath\\vpnbot backup.sql'));

        self::assertSame('vpnbot backup.sql', Import::query()->findOrFail($import->id)->file_name);
    }

    public function testUnpacksABackupAStepAtATime(): void
    {
        $dump = self::longRows();
        $expected = $this->staged($this->stagedBackup($dump));
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload((string) gzencode($dump), 'backup.sql.gz'));
        $part = $this->folder . '/' . $import->id . '/dump.sql.part';

        // A piece of a mebibyte a step, while the budget lasts no longer.
        for ($step = 1; $step <= 3; $step++) {
            self::assertFalse($this->stager()->stage($import, TablesMigrator::bot(), Budget::checks(0)));
            self::assertSame($step * 1_048_576, filesize($part));
            self::assertSame(['done' => $step * 1_048_576, 'total' => null], $this->stager()->progress($import));
        }

        $this->stageInSteps($import, 1000);
        self::assertFileDoesNotExist($part);
        self::assertSame($expected, $this->staged($import));
    }

    public function testGoesOnWithWhatAKilledStepUnpacked(): void
    {
        $dump = self::longRows();
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload($this->zip(['backup.sql' => $dump]), 'backup.zip'));
        // A step killed half-way through unpacking left 1.5 MB, a byte of it unlike the file: it is kept, not written again.
        $written = substr($dump, 0, 1_500_000);
        $first = (int) strpos($written, "'0-1'");
        file_put_contents($this->folder . '/' . $import->id . '/dump.sql.part', substr_replace($written, "'X-1'", $first, 5));

        $this->stageInSteps($import, 1000);

        $ids = array_column($this->staged($import)['user'] ?? [], 'id');
        self::assertCount(360, $ids);
        self::assertSame('X-1', $ids[0]);
        self::assertSame('5-60', $ids[359]);
    }

    public function testRefusesABackupThatUnpacksTooLarge(): void
    {
        $dump = self::longRows();
        $message = sprintf(ImportRefusedException::UNPACKS_TOO_LARGE, Messages::bytes(2_000_000));
        foreach ([[(string) gzencode($dump), 'backup.sql.gz'], [$this->zip(['backup.sql' => $dump]), 'backup.zip']] as [$bytes, $name]) {
            $import = $this->import();
            $this->stager()->keepUpload($import, self::upload($bytes, $name));

            self::assertSame(['file' => [$message]], $this->refusedWith(fn() => $this->stageInSteps($import, 1000, unpackedMost: 2_000_000)), $name);
            self::assertFileDoesNotExist($this->folder . '/' . $import->id . '/dump.sql.part');
        }
    }

    public function testRefusesToFillTheDisk(): void
    {
        $dump = self::longRows();
        $import = $this->import();
        $this->stager()->keepUpload($import, self::upload((string) gzencode($dump), 'backup.sql.gz'));
        // A step that would leave less free on the disk than it must: refused, what it unpacked deleted.
        self::assertFalse($this->stager()->stage($import, TablesMigrator::bot(), Budget::checks(0)));
        self::assertSame(['file' => [ImportRefusedException::NO_SPACE]], $this->refusedWith(fn() => $this->stager(keepFree: PHP_INT_MAX)->stage($import, TablesMigrator::bot(), Budget::unlimited())));
        self::assertFileDoesNotExist($this->folder . '/' . $import->id . '/dump.sql.part');
        // An upload too large for the disk is refused before it is kept.
        self::assertSame(['file' => [ImportRefusedException::NO_SPACE]], $this->refusedWith(fn() => $this->stager(keepFree: PHP_INT_MAX)->keepUpload($this->import(), self::upload($dump))));
    }

    public function testLetsGoOfTheFilesOnceTheReadingIsDoneNotTheRows(): void
    {
        $import = $this->stagedBackup(self::fixture('mysqldump.sql'));
        $progress = $this->stager()->progress($import);

        $this->stager()->readingDone($import);

        self::assertDirectoryDoesNotExist($this->folder . '/' . $import->id);
        self::assertSame(self::USERS, $this->staged($import)['user'] ?? null);
        self::assertTrue($this->stager()->stage($import, TablesMigrator::bot(), Budget::unlimited()));
        self::assertSame($progress, $this->stager()->progress($import));
        self::assertTrue($this->stager()->schema($import)->has('user'));
    }

    public function testDiscardsTheFolderAndTheRowsOfTheImportAlone(): void
    {
        $import = $this->stagedBackup(self::fixture('mysqldump.sql'));
        $other = $this->stagedBackup(self::fixture('phpmyadmin.sql'));
        // A copy too large for one delete.
        for ($i = 0; $i < 20; $i++) {
            $this->db()->table('import_rows')->insert(array_fill(0, 300, ['import_id' => $import->id, 'table_name' => 'user', 'data' => '{}']));
        }

        $this->stager()->discard($import);

        self::assertDirectoryDoesNotExist($this->folder . '/' . $import->id);
        self::assertSame(0, $this->db()->table('import_rows')->where('import_id', $import->id)->count());
        self::assertSame([], $this->staged($import));
        self::assertNotSame([], $this->staged($other));
        self::assertDirectoryExists($this->folder . '/' . $other->id);
    }

    /**
     * A zip of `$files`, as its bytes.
     *
     * @param array<string, string> $files By their names in the zip
     */
    public function zip(array $files): string
    {
        $path = $this->scratchDir() . '/made.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $bytes) {
            $zip->addFromString((string) $name, $bytes);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    /** A dump of 360 customers with long notes, 60 a statement: over 3 MiB, ids `0-1` to `5-60`. */
    private static function longRows(): string
    {
        $dump = "CREATE TABLE `user` (`id` varchar(20), `username` varchar(50), `description_blocking` text);\n";
        for ($statement = 0; $statement < 6; $statement++) {
            $dump .= 'INSERT INTO `user` VALUES ' . implode(',', array_map(static fn(int $i): string => "('{$statement}-{$i}','u','" . str_repeat("long \\'text\\' {$i};", 600) . "')", range(1, 60))) . ";\n";
        }

        return $dump;
    }

    /**
     * A dump of 750 customers — 700 in one statement, longer than a batch, then one a statement — with a table the
     * migrator does not read between, and two panels with their passwords.
     */
    private static function manyRows(): string
    {
        $sql = "CREATE TABLE `user` (`id` varchar(20), `username` varchar(50), `Balance` int, `extra` text);\n";
        $sql .= 'INSERT INTO `user` VALUES ' . implode(',', array_map(static fn(int $i): string => "('{$i}','name_{$i}',{$i},'x')", range(1, 700))) . ";\n";
        $sql .= "CREATE TABLE `textbot` (`id_text` varchar(10), `text` text);\nINSERT INTO `textbot` VALUES ('a','b'),('c','d');\n";
        for ($i = 701; $i <= 750; $i++) {
            $sql .= "INSERT INTO `user` (`id`, `username`) VALUES ('{$i}', 'one_{$i}');\n";
        }

        return $sql . "INSERT INTO `marzban_panel` (`name_panel`, `password_panel`) VALUES ('p1', 'secret1'), ('p2', 'secret2');\n";
    }
}
