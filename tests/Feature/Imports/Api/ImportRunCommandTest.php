<?php

declare(strict_types=1);

namespace Tests\Feature\Imports\Api;

use App\Console\Commands\ImportRunCommand;
use App\Core\Drivers\Registry;
use App\Core\Support\FileLock;
use App\Core\Support\Sleeper;
use App\Modules\Imports\Budget;
use App\Modules\Imports\Enums\ImportPhase;
use App\Modules\Imports\Imports;
use App\Modules\Imports\Models\Import;
use App\Modules\Imports\Records\ImportedCustomer;
use App\Modules\Imports\Sources\Stager;
use App\Modules\Users\Models\User;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeMigrator;
use Tests\Fakes\RecordingSleeper;

/**
 * `import:run <id>`: an import worked from a shell — read through to its review, written through to its end with its
 * tally printed, its progress after each step —; a step already on the import waited for, a while; a failed one says
 * why, and an import that is not there is said, with an exit code a script can read.
 */
final class ImportRunCommandTest extends DatabaseTestCase
{
    private FakeMigrator $migrator;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new FakeMigrator();
        $this->folder = $this->scratchDir();
        $container = $this->app()->container();
        $stager = $container->make(Stager::class, ['folder' => $this->folder]);
        $this->swap(Stager::class, $stager);
        $this->swap(Imports::class, $container->make(Imports::class, ['migrators' => new Registry([$this->migrator]), 'stager' => $stager, 'folder' => $this->folder]), ImportRunCommand::class);
    }

    public function testAnImportIsReadThroughToItsReviewThenWrittenThroughToItsEnd(): void
    {
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali', balance: '25000'), new ImportedCustomer('2', 0, 'nobody')];
        $import = $this->uploaded(ImportsApiTest::DUMP);

        $read = $this->runFor($import->id);
        self::assertSame(Command::SUCCESS, $read->getStatusCode(), $read->getDisplay());
        self::assertStringContainsString("Import #{$import->id} is read.", $read->getDisplay());
        self::assertSame(ImportPhase::Review, $import->refresh()->phase, 'the review is the panel\'s');
        self::assertSame(Command::SUCCESS, $this->runFor($import->id)->getStatusCode(), 'in review: nothing to step');

        $this->service(Imports::class)->start($import);
        $written = $this->runFor($import->id);
        self::assertSame(Command::SUCCESS, $written->getStatusCode(), $written->getDisplay());
        $display = $written->getDisplay();
        self::assertMatchesRegularExpression('/customers\s+1\s+0\s+1/', $display, 'what each kind came to');
        self::assertStringContainsString('not_telegram: 1', $display);
        self::assertStringContainsString("Import #{$import->id} is done, with 1 problem(s).", $display);
        self::assertSame(ImportPhase::Done, $import->refresh()->phase);
        self::assertSame('25000.00', User::query()->where('telegram_id', 501)->sole()->balance());
    }

    public function testAnImportOfAnotherBotsDataFailsAndSaysWhy(): void
    {
        $import = $this->uploaded("CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n");

        $run = $this->runFor($import->id);

        self::assertSame(Command::FAILURE, $run->getStatusCode());
        self::assertSame(ImportPhase::Failed, $import->refresh()->phase);
        self::assertStringContainsString('members', $run->getDisplay(), 'the owner\'s words: what it lacks');
    }

    public function testAnImportNotThereOrCancelledIsSaid(): void
    {
        self::assertSame(Command::INVALID, $this->runFor(99)->getStatusCode());
        self::assertStringContainsString('No import #99.', $this->runFor(99)->getDisplay());

        $import = $this->uploaded(ImportsApiTest::DUMP);
        $this->service(Imports::class)->cancel($import);
        $cancelled = $this->runFor($import->id);
        self::assertSame(Command::FAILURE, $cancelled->getStatusCode());
        self::assertStringContainsString("Import #{$import->id} was cancelled.", $cancelled->getDisplay());
    }

    public function testEachStepsProgressIsPrintedAsItGoes(): void
    {
        $this->migrator->customers = [new ImportedCustomer('1', 501, 'ali'), new ImportedCustomer('2', 502, 'sara'), new ImportedCustomer('3', 503, 'reza')];
        $import = $this->uploaded(ImportsApiTest::DUMP);
        $budget = static fn(): Budget => Budget::checks(2);

        $read = $this->runFor($import->id, new RecordingSleeper(), $budget);
        self::assertSame(Command::SUCCESS, $read->getStatusCode(), $read->getDisplay());
        self::assertMatchesRegularExpression('/^  Read \d+ of ' . strlen(ImportsApiTest::DUMP) . '\r?$/m', $read->getDisplay(), 'the bytes read after a step that left it reading');

        $this->service(Imports::class)->start($import);
        $written = $this->runFor($import->id, new RecordingSleeper(), $budget);
        self::assertSame(Command::SUCCESS, $written->getStatusCode(), $written->getDisplay());
        self::assertStringContainsString('  Importing customers: 2 of 3', $written->getDisplay(), "a stage's things written, of how many");
        self::assertSame(ImportPhase::Done, $import->refresh()->phase);
    }

    public function testAStepAlreadyOnTheImportIsWaitedForThenGoneOnFrom(): void
    {
        $import = $this->uploaded(ImportsApiTest::DUMP);
        $lock = FileLock::take("{$this->folder}/{$import->id}.lock") ?? self::fail("The import's lock could not be taken.");
        $sleeper = new class ($lock->release(...)) implements Sleeper {
            /** @var list<int> */
            public array $seconds = [];

            public function __construct(private ?\Closure $thenFree) {}

            public function sleep(int $seconds): void
            {
                $this->seconds[] = $seconds;
                if ($this->thenFree !== null) {
                    ($this->thenFree)();
                    $this->thenFree = null;
                }
            }

            public function usleep(int $microseconds): void {}
        };

        $run = $this->runFor($import->id, $sleeper);

        self::assertSame(Command::SUCCESS, $run->getStatusCode(), $run->getDisplay());
        self::assertSame([ImportRunCommand::WAIT_SECONDS], $sleeper->seconds, 'waited once, until the other step let go');
        self::assertStringContainsString("Another step of import #{$import->id} is running (the panel's, or another import:run): waiting for it", $run->getDisplay());
        self::assertSame(ImportPhase::Review, $import->refresh()->phase);
    }

    public function testAStepThatHoldsTheImportTooLongIsLeftToIt(): void
    {
        $import = $this->uploaded(ImportsApiTest::DUMP);
        $lock = FileLock::take("{$this->folder}/{$import->id}.lock") ?? self::fail("The import's lock could not be taken.");
        $sleeper = new RecordingSleeper();

        try {
            $run = $this->runFor($import->id, $sleeper);
        } finally {
            $lock->release();
        }

        self::assertSame(Command::FAILURE, $run->getStatusCode());
        self::assertSame(array_fill(0, ImportRunCommand::WAITS, ImportRunCommand::WAIT_SECONDS), $sleeper->seconds, 'a while, not for ever');
        self::assertSame(1, substr_count($run->getDisplay(), 'waiting for it'), 'said once');
        self::assertStringContainsString("Another step of import #{$import->id} is still running after 5 minutes.", $run->getDisplay());
        self::assertSame(ImportPhase::Reading, $import->refresh()->phase, 'nothing read');
    }

    /** An import of the fake bot begun from `$dump`, uploaded as the panel does. */
    private function uploaded(string $dump): Import
    {
        return $this->service(Imports::class)->fromBackup(FakeMigrator::KEY, new UploadedFile((new StreamFactory())->createStream($dump), 'backup.sql', null, strlen($dump)), 'root');
    }

    /**
     * `import:run <id>` as the console runs it — or waiting with `$sleeper` and stepping by `$budget` when a test gives
     * them.
     *
     * @param (\Closure(): Budget)|null $budget
     */
    private function runFor(int $id, ?Sleeper $sleeper = null, ?\Closure $budget = null): CommandTester
    {
        $command = $sleeper === null ? $this->service(ImportRunCommand::class) : new ImportRunCommand($this->service(Imports::class), $sleeper, $budget);
        $tester = new CommandTester($command);
        $tester->execute(['id' => (string) $id]);

        return $tester;
    }
}
