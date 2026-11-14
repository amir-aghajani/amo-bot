<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Core;

use App\Core\Security\Encrypter;
use App\Core\Support\FileLock;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Choices;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Enums\ImportStage;
use App\Modules\Imports\Exceptions\ImportRefusedException;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Links;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Models\ImportLink;
use App\Modules\Imports\Models\ImportProblem;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Records\ImportedPanel;
use App\Modules\Imports\Sources\RowWriter;
use App\Modules\Imports\Tasks\PruneImportsTask;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Fakes\FakeMigrator;

/**
 * An import's life: begun from a backup file (refused for an unknown bot, while another is open, for a file that is no
 * backup — leaving nothing behind), read a step at a time into its review — or failed, for another bot's data —, one
 * step at a time, started, written to its end, cancelled or pruned, its staged copy gone once it ends; and what the
 * panels read of it.
 */
final class LifecycleTest extends ImportTestCase
{
    /** The fake bot's backup: its one table, a secret column among its columns. */
    private const DUMP = "CREATE TABLE `members` (`id` int(11) NOT NULL, `name` varchar(64), `secret` varchar(64));\n"
        . "INSERT INTO `members` (`id`, `name`, `secret`) VALUES (1, 'Ali', 'pw-1'), (2, 'Sara', NULL);\n";

    public function testABackupIsReadIntoItsReviewWithItsPanelsConnectionsKeptApart(): void
    {
        $this->migrator->panels = [new ImportedPanel('de', 'آلمان', 'x-ui_single', 'https://panel.example:2053', '3x-ui', null, ['base_url' => 'https://panel.example:2053', 'api_token' => 'very-secret'], 4, active: false, capacity: 300)];
        $import = $this->uploaded(self::DUMP);
        self::assertSame([ImportPhase::Reading, 'backup.sql', 'root', 1], [$import->phase, $import->file_name, $import->reviewer, $import->bot_id]);

        $import = $this->step($import, Budget::unlimited());

        self::assertSame(ImportPhase::Review, $import->phase);
        self::assertSame('1.0', $import->version, 'the migrator\'s recognition tells');
        self::assertSame('de', $import->survey['panels'][0]['key'] ?? null);
        self::assertStringNotContainsString('very-secret', (string) json_encode($import->survey), 'the survey never carries a connection');
        self::assertStringNotContainsString('very-secret', (string) $import->panels, 'kept encrypted');
        self::assertSame(['de' => ['is_active' => false, 'capacity' => 300, 'base_url' => 'https://panel.example:2053', 'api_token' => 'very-secret']], json_decode($this->service(Encrypter::class)->decrypt((string) $import->panels), true), 'with whether it sells and how much, as its server would');
        self::assertFalse(is_dir("{$this->folder}/{$import->id}"), 'read: the backup file (the whole old database) is gone');
        self::assertGreaterThan(0, ImportRow::query()->where('import_id', $import->id)->count(), 'the staged copy waits for the writing');
    }

    public function testAnotherBotsDataFailsTheImportInTheOwnersWordsAndIsDiscarded(): void
    {
        $import = $this->uploaded("CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n");

        $import = $this->step($import, Budget::unlimited());

        self::assertSame(ImportPhase::Failed, $import->phase);
        self::assertSame(sprintf(ImportRefusedException::NOT_THIS_BOT, "\u{2068}" . FakeMigrator::LABEL . "\u{2069}", "\u{2068}members\u{2069}"), $import->error);
        self::assertNotNull($import->finished_at);
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count());
        self::assertFalse(is_dir("{$this->folder}/{$import->id}"), 'its file gone');
    }

    public function testAFileThatCannotBeReadToItsEndFailsTheImportInTheSourcesWords(): void
    {
        $import = $this->uploaded("CREATE TABLE `members` (`id` int, `name` varchar(9));\nINSERT INTO `members` VALUES (1, 'unterminated");

        $import = $this->step($import, Budget::unlimited());

        self::assertSame([ImportPhase::Failed, ImportRefusedException::BROKEN], [$import->phase, $import->error]);
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count());
    }

    public function testAReadingWhoseStagedRowsAreGoneSinceFailsRatherThanGoOnWithoutThem(): void
    {
        $import = $this->uploaded(self::DUMP);
        for ($steps = 0; ImportRow::query()->where('import_id', $import->id)->count() === 0; $steps++) {
            self::assertLessThan(10, $steps, 'a row is staged');
            $import = $this->step($import, Budget::checks(1));
        }
        self::assertSame(ImportPhase::Reading, $import->phase, 'part of it staged, the rest still to read');
        self::assertSame(ImportRow::query()->where('import_id', $import->id)->count(), $import->reading[RowWriter::STAGED] ?? null, 'the reading knows how many it staged');

        // A restore of the database empties the staged copy; the file and the reading's place in it stay.
        ImportRow::query()->where('import_id', $import->id)->delete();
        $import = $this->step($import, Budget::unlimited());

        self::assertSame([ImportPhase::Failed, Imports::STAGED_GONE], [$import->phase, $import->error]);
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count());
        self::assertFalse(is_dir("{$this->folder}/{$import->id}"), 'discarded');
    }

    public function testADataOnlyExportIsSaidToLackItsTablesStructure(): void
    {
        $import = $this->uploaded("INSERT INTO `members` VALUES (1, 'Ali', NULL);\nINSERT INTO `orders` VALUES (7);\nINSERT INTO `users` VALUES (1);\nINSERT INTO `wallets` VALUES (1);\n");

        $import = $this->step($import, Budget::unlimited());

        self::assertSame(ImportPhase::Failed, $import->phase);
        self::assertSame(sprintf(ImportRefusedException::NO_STRUCTURE, "\u{2068}members\u{2069}"), $import->error, 'the tables of its bot it could not read, and how to export it again');
    }

    public function testDataTheMigratorCannotReadFailsTheImportRatherThanStopEveryStep(): void
    {
        $logs = $this->logs();
        $import = $this->uploaded(self::DUMP);
        $this->migrator->surveyFails = new \UnexpectedValueException('A row of members is not what it should be.');

        $import = $this->step($import, Budget::unlimited());

        self::assertSame([ImportPhase::Failed, Imports::BROKEN], [$import->phase, $import->error]);
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count(), 'discarded');
        self::assertTrue($logs->hasErrorThatContains('A row of members is not what it should be.'));

        $this->migrator->surveyFails = null;
        $this->migrator->customers = [new ImportedCustomer('1', 1001), new ImportedCustomer('2', 1002)];
        $this->migrator->beforeEach = static function (int $seq): void {
            if ($seq === 2) {
                throw new \UnexpectedValueException('The second customer cannot be made.');
            }
        };
        $writing = $this->step($this->started(), Budget::unlimited());

        self::assertSame([ImportPhase::Failed, Imports::BROKEN], [$writing->phase, $writing->error]);
        self::assertSame([1001], User::query()->pluck('telegram_id')->all(), 'what came before it stays');
    }

    public function testAnImportBegunIsSteppedOnlyOnceItsSourceIsKept(): void
    {
        $seen = [];
        $import = $this->whileListening('eloquent.saved: ' . Import::class, function (Import $saving) use (&$seen): void {
            // Another tab finds the import open — made a moment ago, its upload still being kept — and steps it.
            $found = Import::query()->findOrFail($saving->id);
            $ran = $this->imports()->advance($found, Budget::unlimited());
            $seen[] = [$ran, $found->phase, $found->error];
        }, fn() => $this->uploaded(self::DUMP));

        self::assertGreaterThanOrEqual(2, count($seen), 'stepped as it was made, and as its upload was kept');
        self::assertSame([], array_values(array_filter($seen, static fn(array $step): bool => $step[1] !== ImportPhase::Reading || $step[2] !== null)), 'nothing read, nothing failed meanwhile');
        self::assertFalse(end($seen)[0], 'its lock held while its source is kept');
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count());
        self::assertTrue($this->imports()->advance($import, Budget::unlimited()), 'once kept, a step runs');
        self::assertSame(ImportPhase::Review, $import->phase);
    }

    public function testAnImportIsRefusedForAnUnknownBotWhileAnotherIsOpenAndForAFileThatIsNoBackup(): void
    {
        self::assertSame(['migrator' => [ImportRefusedException::UNKNOWN_MIGRATOR]], $this->refusedWith(fn() => $this->imports()->fromBackup('mirza', $this->file(self::DUMP), 'root')));

        self::assertArrayHasKey('file', $this->refusedWith(fn() => $this->uploaded('Hello, this is no database.')));
        self::assertSame(0, Import::query()->count(), 'a refused file leaves no import behind');

        $this->uploaded(self::DUMP);
        self::assertSame(['import' => [ImportRefusedException::BUSY]], $this->refusedWith(fn() => $this->uploaded(self::DUMP)));
        self::assertSame(['import' => [ImportRefusedException::BUSY]], $this->refusedWith(fn() => $this->imports()->fromDatabase(FakeMigrator::KEY, ['host' => 'db'], 'root')));
    }

    public function testOneStepAtATimeASecondFindsTheImportAsItStands(): void
    {
        $import = $this->uploaded(self::DUMP);
        $lock = FileLock::take("{$this->folder}/{$import->id}.lock") ?? self::fail('The import\'s lock could not be taken.');
        try {
            $answered = $this->step($import, Budget::unlimited());
            $ran = $this->imports()->advance($import, Budget::unlimited());
        } finally {
            $lock->release();
        }

        self::assertSame(ImportPhase::Reading, $answered->phase, 'nothing read meanwhile');
        self::assertFalse($ran, 'and said so: the caller waits before asking again');
        self::assertTrue($this->imports()->advance($import, Budget::unlimited()));
        self::assertSame(ImportPhase::Review, $import->phase);
    }

    public function testAnImportIsStartedOnceFromTheReviewAndWrittenToItsEnd(): void
    {
        $this->migrator->customers = [new ImportedCustomer('1', 1001)];
        $import = $this->imports()->start($this->reviewed());
        self::assertSame([ImportPhase::Importing, ImportStage::Servers, 0], [$import->phase, $import->stage, $import->cursor]);
        self::assertNotNull($import->started_at);
        self::assertSame(array_diff_key(Choices::propose($import->survey ?? [])->toArray(), ['panels' => true]), array_diff_key($import->choices ?? [], ['panels' => true]), 'none saved: what the review proposed');
        self::assertSame(['import' => [ImportRefusedException::NOT_NOW]], $this->refusedWith(fn() => $this->imports()->start($import)));
        self::assertSame(['import' => [ImportRefusedException::NOT_NOW]], $this->refusedWith(fn() => $this->imports()->choose($import, [])));

        $import = $this->step($import, Budget::unlimited());
        self::assertSame(ImportPhase::Done, $import->phase);
        self::assertNotNull($import->finished_at);
        self::assertTrue(User::query()->where('telegram_id', 1001)->exists());
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count(), 'its staged copy gone once it ends');
        self::assertNull($import->panels, 'and its panels\' connections');
        self::assertNull($this->imports()->current());
        self::assertSame([$import->id], array_map(static fn(Import $ended): int => $ended->id, $this->imports()->history()));
    }

    public function testAnImportWhoseStagedCopyIsGoneFails(): void
    {
        $this->migrator->customers = [new ImportedCustomer('1', 1001)];
        $import = $this->imports()->start($this->reviewed());
        ImportRow::query()->where('import_id', $import->id)->delete();

        $import = $this->step($import, Budget::unlimited());

        self::assertSame([ImportPhase::Failed, Imports::STAGED_GONE], [$import->phase, $import->error]);
        self::assertFalse(User::query()->exists());
    }

    public function testCancellingAnOpenImportDiscardsItAndDeletingAnEndedOneKeepsWhatItBrought(): void
    {
        $open = $this->uploaded(self::DUMP);
        $this->step($open, Budget::unlimited());
        self::assertGreaterThan(0, ImportRow::query()->where('import_id', $open->id)->count());

        $this->imports()->cancel($open);
        self::assertSame(ImportPhase::Cancelled, $open->refresh()->phase);
        self::assertSame(0, ImportRow::query()->where('import_id', $open->id)->count());
        self::assertNull($open->panels, 'nor its panels\' connections');
        self::assertSame(ImportPhase::Cancelled, $this->step($open, Budget::unlimited())->phase, 'a cancelled import steps no more');

        $this->migrator->customers = [new ImportedCustomer('1', 0)];
        $done = $this->importAll();
        self::assertSame(1, ImportProblem::query()->where('import_id', $done->id)->count());
        $this->migrator->customers = [new ImportedCustomer('2', 2002)];
        $kept = $this->importAll();
        $this->imports()->cancel($kept);
        $this->imports()->cancel($done);
        self::assertSame([], Import::query()->whereKey([$done->id, $kept->id])->pluck('id')->all());
        self::assertSame(0, ImportProblem::query()->count(), 'its problems with it');
        self::assertTrue(ImportLink::query()->where(['kind' => Links::CUSTOMER, 'source_key' => '2'])->exists(), 'what it brought stays known');
    }

    public function testPruneCancelsAnImportLeftUntouchedForAWeek(): void
    {
        $import = $this->uploaded(self::DUMP);
        $this->step($import, Budget::unlimited());
        Import::query()->whereKey($import->id)->update(['updated_at' => now()->subDays(Imports::IDLE_DAYS)->subMinute()]);

        (new PruneImportsTask($this->imports()))->run();

        self::assertSame(ImportPhase::Cancelled, $import->refresh()->phase);
        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count());

        $fresh = $this->uploaded(self::DUMP);
        $this->imports()->prune();
        self::assertSame(ImportPhase::Reading, $fresh->refresh()->phase, 'one touched lately stays');
    }

    public function testPruneDiscardsWhatAnEndedImportStillKeeps(): void
    {
        $import = $this->uploaded(self::DUMP);
        $this->step($import, Budget::unlimited());
        // A cancel whose step was cut short: ended, its staged copy (and a database's connection) still there.
        Import::query()->whereKey($import->id)->update(['phase' => ImportPhase::Cancelled->value, 'connection' => 'encrypted-connection']);

        $this->imports()->prune();

        self::assertSame(0, ImportRow::query()->where('import_id', $import->id)->count());
        self::assertNull($import->refresh()->connection);
    }

    public function testPruneDeletesTheFolderAndLockOfAnImportARestoreTookAway(): void
    {
        $gone = $this->uploaded(self::DUMP);
        touch($this->folder . '/' . $gone->id . '.lock');
        self::assertDirectoryExists($this->folder . '/' . $gone->id, 'the uploaded file is kept in its folder');
        // A restore of an older backup of the whole installation: the import's record is not in it.
        Import::query()->whereKey($gone->id)->delete();
        $open = $this->uploaded(self::DUMP);

        $this->imports()->prune();

        self::assertDirectoryDoesNotExist($this->folder . '/' . $gone->id, 'the other bot\'s data, its passwords in it');
        self::assertFileDoesNotExist($this->folder . '/' . $gone->id . '.lock');
        self::assertDirectoryExists($this->folder . '/' . $open->id, 'an import still there keeps its own');
        self::assertFileExists($this->folder . '/begin.lock', 'and the folder\'s own lock stays');
    }

    public function testTheImportAsThePanelsReadIt(): void
    {
        Carbon::setTestNow('2026-11-13 12:00:00');
        $this->fakePanel();
        $server = $this->fakeServer('آلمان', ['base_url' => 'https://de.example:443/panel']);
        $this->migrator->panels = [
            self::fakePanelOf('same-origin', 'آلمان', 3, url: 'https://DE.example/'),
            self::fakePanelOf('elsewhere', 'هلند', 1, ['base_url' => 'https://nl.example'], 'https://nl.example'),
            new ImportedPanel('manual', 'فروش دستی', 'Manualsale', null, null, 'کانکتوری ندارد', [], 2),
        ];
        $this->migrator->customers = [new ImportedCustomer('1', 0)];
        $import = $this->reviewed();

        $state = $this->imports()->present($import);
        self::assertSame(['id' => $import->id, 'migrator' => FakeMigrator::KEY, 'migrator_label' => FakeMigrator::LABEL, 'source' => 'backup', 'file_name' => 'backup.sql', 'phase' => 'review'], array_intersect_key($state, array_flip(['id', 'migrator', 'migrator_label', 'source', 'file_name', 'phase'])));
        self::assertSame([$server->id, null, null], array_column($state['survey']['panels'], 'suggested_server_id'), 'a server of the same connector on the same address');
        self::assertNull($state['choices']);
        self::assertSame(['action' => 'server', 'server_id' => $server->id], (array) $state['proposed']['panels']->{'same-origin'});
        self::assertSame(['action' => 'skip', 'server_id' => null], (array) $state['proposed']['panels']->manual);
        self::assertSame(['balances' => true, 'admins' => false, 'channels' => false], array_intersect_key($state['proposed'], array_flip(['balances', 'admins', 'channels'])));
        self::assertNull($state['counts']);
        self::assertSame(['total' => 0, 'by_reason' => []], $state['problems']);
        self::assertSame(['stage' => null, 'stage_label' => null, 'done' => 0, 'total' => null], $state['progress']);

        $this->imports()->choose($import, ['panels' => ['same-origin' => ['action' => 'skip'], 'elsewhere' => ['action' => 'add']]]);
        $state = $this->imports()->present($import->refresh());
        self::assertNull($state['proposed'], 'saved choices are what it shows');
        self::assertSame('add', ((array) $state['choices']['panels'])['elsewhere']['action']);

        $this->imports()->start($import);
        $state = $this->imports()->present($import->refresh());
        self::assertSame(['stage' => 'servers', 'stage_label' => 'سرورها', 'done' => 0, 'total' => 3], $state['progress']);
        self::assertSame(['created' => 0, 'linked' => 0, 'skipped' => 0], $state['counts']['customers']);
        self::assertSame('2026-11-13T12:00:00+00:00', $state['started_at']);
    }

    public function testTheProblemsAreReadAPageAtATimeByReasonAndKind(): void
    {
        $this->migrator->customers = array_map(static fn(int $i): ImportedCustomer => new ImportedCustomer("c{$i}", 0), range(1, 5));
        $import = $this->importAll(['cards' => true]);

        $page = $this->imports()->problems($import, 'not_telegram', 'customers', 2, 2);
        self::assertSame(5, $page['total']);
        self::assertSame(['c3', 'c4'], array_map(static fn(ImportProblem $problem): string => $problem->source_key, $page['problems']));
        self::assertSame(0, $this->imports()->problems($import, 'used_up', null, 1)['total']);
        self::assertSame(0, $this->imports()->problems($import, null, 'cards', 1)['total']);
        self::assertSame(['total' => 5, 'by_reason' => [['reason' => 'not_telegram', 'label' => 'شناسه تلگرام معتبر ندارد', 'count' => 5]]], $this->imports()->present($import)['problems']);
    }

    private function uploaded(string $dump): Import
    {
        return $this->imports()->fromBackup(FakeMigrator::KEY, $this->file($dump), 'root');
    }

    private function file(string $dump): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($dump), 'backup.sql', null, strlen($dump));
    }
}
