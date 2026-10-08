<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use App\Core\Scheduling\Task;
use App\Core\Support\FileLock;
use DI\Container;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Tests\Fakes\CountingTask;
use Tests\Fakes\FailingTask;
use Tests\Fakes\ListedShops;
use Tests\Fakes\TimedTask;
use Tests\TestCase;

use function DI\factory;

/**
 * The minute-cron scheduler: a task runs once its interval has passed since its last run (or on `force`), last runs
 * are remembered in the state file — a task's time written before it runs —, one run at a time, and one broken task
 * (or one that cannot even be made) never stops the others; a state that cannot be kept is logged, not fatal. A shop's
 * task runs in every shop; one run's time is shared out over its turns.
 */
final class SchedulerTest extends TestCase
{
    private string $stateFile;
    private Container $container;
    private CountingTask $task;
    private TestHandler $log;
    private Budget $budget;
    private ListedShops $shops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateFile = $this->scratchDir() . '/cache/schedule.json';
        $this->task = new CountingTask();
        $this->log = new TestHandler();
        $this->budget = new Budget();
        $this->shops = new ListedShops();

        $this->container = new Container();
        $this->container->set(CountingTask::class, $this->task);
        $this->container->set(FailingTask::class, new FailingTask());
        $this->container->set(TimedTask::class, new TimedTask($this->budget));
    }

    public function testEverythingIsDueOnTheFirstRunAndNothingRightAfter(): void
    {
        self::assertNull($this->scheduler()->lastRunAt(), 'never ran');

        $scheduler = $this->scheduler()->everyMinutes(5, CountingTask::class);
        self::assertSame([CountingTask::class], $scheduler->run());
        self::assertSame(1, $this->task->runs);
        self::assertEqualsWithDelta(time(), $scheduler->lastRunAt()?->getTimestamp(), 2);
        self::assertFileExists($this->stateFile, 'the last runs are kept for the next tick, the folder made when missing');

        self::assertSame([], $scheduler->run(), 'inside the interval');
        self::assertSame(1, $this->task->runs);
    }

    public function testATaskRunsAgainOnceItsIntervalHasPassed(): void
    {
        $scheduler = $this->scheduler()->everyMinutes(5, CountingTask::class);
        $scheduler->run();

        $this->backdate(CountingTask::class, 4 * 60);
        self::assertSame([], $scheduler->run(), 'four minutes: not yet');

        $this->backdate(CountingTask::class, 5 * 60);
        self::assertSame([CountingTask::class], $scheduler->run(), 'five minutes: due');
        self::assertSame(2, $this->task->runs);
    }

    public function testARunWhileAnotherHoldsTheLockDoesNothing(): void
    {
        $scheduler = $this->scheduler()->everyMinutes(5, CountingTask::class);
        $other = FileLock::take($this->stateFile . '.lock');
        self::assertNotNull($other, 'another run (the cron, the /cron address, the poller) holds it');

        try {
            self::assertSame([], $scheduler->run(), 'the second run steps aside');
            self::assertSame(0, $this->task->runs);
        } finally {
            $other->release();
        }

        self::assertSame([CountingTask::class], $scheduler->run(), 'and the next tick runs as usual');
    }

    public function testForceRunsEverythingWhateverTheClockSays(): void
    {
        $scheduler = $this->scheduler()->everyMinutes(5, CountingTask::class);
        $scheduler->run();

        self::assertSame([CountingTask::class], $scheduler->run(force: true));
        self::assertSame(2, $this->task->runs);
    }

    public function testATasksTimeIsWrittenBeforeItRuns(): void
    {
        // A task that dies on the way (memory, a killed request) waits its interval like any other.
        $task = new class ($this->stateFile) implements Task {
            public ?int $stampedAt = null;

            public function __construct(private readonly string $stateFile) {}

            public function run(): void
            {
                $state = json_decode((string) file_get_contents($this->stateFile), true);
                $this->stampedAt = is_array($state) ? ($state[CountingTask::class] ?? null) : null;
            }
        };
        $this->container->set(CountingTask::class, $task);

        $this->scheduler()->everyMinutes(5, CountingTask::class)->run();

        self::assertNotNull($task->stampedAt, 'its time was in the state file while it ran');
        self::assertEqualsWithDelta(time(), $task->stampedAt, 2);
    }

    public function testATaskThatCannotBeMadeIsLoggedAndTheOthersStillRun(): void
    {
        $this->container->set(TimedTask::class, factory(static fn(): never => throw new \RuntimeException('a dependency is missing')));

        $scheduler = $this->scheduler()->everyMinutes(1, TimedTask::class)->everyMinutes(1, CountingTask::class);

        self::assertSame([CountingTask::class], $scheduler->run());
        self::assertTrue($this->log->hasErrorThatPasses(static fn(LogRecord $record): bool => str_contains($record->message, 'cannot be made') && $record->context['task'] === TimedTask::class && $record->context['exception'] instanceof \Throwable), 'named, with the exception for the trace');
        self::assertSame([], $scheduler->run(), 'its interval counts from the attempt, like a task that failed');
    }

    public function testAStateThatCannotBeKeptIsLoggedAndTheTasksStillRun(): void
    {
        // A file stands where the state's folder would be made: no lock, no state — a shop whose tasks never run is worse
        // off than one whose runs may overlap.
        $blocked = $this->scratchDir() . '/in-the-way';
        file_put_contents($blocked, '');
        $scheduler = new Scheduler($this->container, $this->shops, $this->budget, new Logger('test', [$this->log]), $blocked . '/cache/schedule.json');

        self::assertSame([CountingTask::class], $scheduler->everyMinutes(5, CountingTask::class)->run());
        self::assertTrue($this->log->hasWarningThatContains('The scheduler runs without its lock'));
        self::assertTrue($this->log->hasWarningThatContains('cannot keep when its tasks ran'));
        self::assertNull($scheduler->lastRunAt(), 'nothing kept');
    }

    public function testABrokenTaskIsLoggedAndTheOthersStillRun(): void
    {
        $scheduler = $this->scheduler()->everyMinutes(5, CountingTask::class)->everyMinutes(1, FailingTask::class);

        self::assertSame([CountingTask::class], $scheduler->run(), 'the one that threw did not run');
        self::assertTrue($this->log->hasErrorThatPasses(static fn(LogRecord $record): bool => $record->context['task'] === FailingTask::class && $record->context['exception'] instanceof \RuntimeException), 'named, with the exception for the trace');
        self::assertSame(1, $this->task->runs);

        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertEqualsWithDelta(time(), $state[FailingTask::class], 2, 'its interval counts from the attempt, so it is not retried every tick');
    }

    public function testAShopsTaskRunsInEveryShopAndTheServersTaskAcrossThemOnce(): void
    {
        $this->shops->ids = [1, 7];
        $scheduler = $this->scheduler()->everyMinutes(1, CountingTask::class, eachBot: true)->everyMinutes(1, FailingTask::class, eachBot: true);

        self::assertSame([CountingTask::class, FailingTask::class], $scheduler->run(), 'a shop\'s task that failed in a shop still ran in the others');
        self::assertSame([1, 7, 1, 7], $this->shops->ran);
        self::assertSame(2, $this->task->runs);
        self::assertCount(2, array_filter($this->log->getRecords(), static fn(LogRecord $record): bool => $record->context['task'] === FailingTask::class && in_array($record->context['shop'], [1, 7], true)), 'each shop\'s failure, named');

        $this->shops->ran = [];
        $this->scheduler()->everyMinutes(1, CountingTask::class)->run(force: true);
        self::assertSame([null], $this->shops->ran);
    }

    public function testShopsThatCannotBeReadHoldTheShopsTasksBackForTheNextTick(): void
    {
        $this->shops->unreadable = true;
        $scheduler = $this->scheduler()->everyMinutes(1, CountingTask::class, eachBot: true)->everyMinutes(1, TimedTask::class);

        self::assertSame([TimedTask::class], $scheduler->run(), 'the servers\' work goes on');
        self::assertTrue($this->log->hasErrorThatContains('cannot read the shops'));

        $this->shops->unreadable = false;
        self::assertSame([CountingTask::class], $scheduler->run(), 'not marked as run, so due at the next tick');
    }

    public function testOneRunsTimeIsSharedOutOverItsTurns(): void
    {
        $this->shops->ids = [1, 7, 9];
        $scheduler = $this->scheduler()->everyMinutes(1, TimedTask::class, eachBot: true)->everyMinutes(1, CountingTask::class);
        $timed = $this->container->get(TimedTask::class);
        \assert($timed instanceof TimedTask);

        $scheduler->run();

        // Four turns — the timed task in each of three shops, then the counting one — and a turn that is over at once
        // leaves its time to those after it: 45/4, then 45/3, then 45/2.
        self::assertCount(3, $timed->given);
        foreach ([11.25, 15.0, 22.5] as $turn => $seconds) {
            self::assertEqualsWithDelta($seconds, $timed->given[$turn], 0.5, 'each gets what is left over the turns still to come');
        }
        self::assertEqualsWithDelta(Budget::OUTSIDE_A_RUN, $this->budget->seconds(), 0.5, 'outside a run, work gets a turn\'s time of its own');
    }

    private function scheduler(): Scheduler
    {
        return new Scheduler($this->container, $this->shops, $this->budget, new Logger('test', [$this->log]), $this->stateFile);
    }

    /** Pretend the task last ran `$seconds` ago. */
    private function backdate(string $task, int $seconds): void
    {
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $state[$task] = time() - $seconds;
        file_put_contents($this->stateFile, json_encode($state));
    }
}
