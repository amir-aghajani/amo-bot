<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Intervals;
use App\Core\Scheduling\Scheduler;
use App\Core\Scheduling\Task;
use App\Core\Support\FileLock;
use App\Support\LocalTime;
use DI\Container;
use Illuminate\Support\Carbon;
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
 * The minute-cron scheduler: a task runs on its grid — its start on the shop's clock and every interval after it — once
 * the grid's latest moment came since its last run (or on `force`), a late run moving nothing; last runs are remembered
 * in the state file — a task's time written before it runs —, one run at a time, and one broken task (or one that cannot
 * even be made) never stops the others; a state that cannot be kept is logged, not fatal. A shop's task runs in every
 * shop; one run's time is shared out over its turns. Across a daylight-saving change a moment the clock jumps over comes
 * at the first minute after the jump, and a minute of the hour a fall-back repeats runs once. The clock is held: 12:14:30
 * UTC, four and a half minutes into a five-minute grid's moment, fourteen and a half into a thirty-minute one's.
 */
final class SchedulerTest extends TestCase
{
    private string $stateFile;
    private Container $container;
    private CountingTask $task;
    private TestHandler $log;
    private Budget $budget;
    private ListedShops $shops;

    /** The scheduler ranAt() last ran. */
    private Scheduler $lastScheduler;

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
        Carbon::setTestNow('2026-11-26 12:14:30');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testEverythingIsDueOnTheFirstRunAndNothingRightAfter(): void
    {
        self::assertNull($this->scheduler()->lastRunAt(), 'never ran');

        $scheduler = $this->scheduler()->everyMinutes(5, CountingTask::class);
        self::assertSame([CountingTask::class], $scheduler->run());
        self::assertSame(1, $this->task->runs);
        self::assertEqualsWithDelta(Carbon::now()->getTimestamp(), $scheduler->lastRunAt()?->getTimestamp(), 2);
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

    public function testALastRunAheadOfTheClockCountsAsNowAndTheTaskWaitsForItsNextMoment(): void
    {
        $scheduler = $this->scheduler()->everyMinutes(10, CountingTask::class);
        $scheduler->run();

        // The server's clock put back an hour: its last run (12:14:30) is ahead of it.
        Carbon::setTestNow('2026-11-26 11:14:30');
        $next = $scheduler->nextRun(CountingTask::class, $scheduler->lastRuns()[CountingTask::class]->getTimestamp());
        self::assertSame('2026-11-26 11:20', gmdate('Y-m-d H:i', $next->getTimestamp()), 'its next moment, never "at the next tick" while nothing runs');
        self::assertSame([], $scheduler->run(), 'not at once');
        self::assertSame(Carbon::now()->getTimestamp(), $scheduler->lastRuns()[CountingTask::class]->getTimestamp(), 'its time put back to now');
        Carbon::setTestNow('2026-11-26 11:20:00');
        self::assertSame([CountingTask::class], $scheduler->run(), 'and at its next moment, not once the clock caught up an hour later');
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
        self::assertEqualsWithDelta(Carbon::now()->getTimestamp(), $task->stampedAt, 2);
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
        self::assertEqualsWithDelta(Carbon::now()->getTimestamp(), $state[FailingTask::class], 2, 'its interval counts from the attempt, so it is not retried every tick');
    }

    public function testAShopsTaskRunsInEveryShopAndTheServersTaskAcrossThemOnce(): void
    {
        $this->shops->ids = [1, 7];
        $scheduler = $this->scheduler()->everyMinutes(1, CountingTask::class, eachBot: true)->everyMinutes(1, FailingTask::class, eachBot: true);

        self::assertSame([CountingTask::class], $scheduler->run(), 'a shop\'s task that failed in every shop did not run');
        self::assertSame([1, 7, 1, 7], $this->shops->ran);
        self::assertSame(2, $this->task->runs);
        self::assertCount(2, array_filter($this->log->getRecords(), static fn(LogRecord $record): bool => $record->context['task'] === FailingTask::class && in_array($record->context['shop'], [1, 7], true)), 'each shop\'s failure, named');

        $this->shops->ran = [];
        $this->scheduler()->everyMinutes(1, CountingTask::class)->run(force: true);
        self::assertSame([null], $this->shops->ran);
    }

    public function testAShopsTaskThatFailedInAShopStillRanInTheOthers(): void
    {
        $this->shops->ids = [1, 7];
        // Goes through in the first shop, breaks in the second.
        $task = new class implements Task {
            public int $calls = 0;

            public function run(): void
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('the second shop broke it');
                }
            }
        };
        $this->container->set($task::class, $task);

        self::assertSame([$task::class], $this->scheduler()->everyMinutes(1, $task::class, eachBot: true)->run());
        self::assertSame(2, $task->calls);
        self::assertTrue($this->log->hasErrorThatPasses(static fn(LogRecord $record): bool => $record->context['shop'] === 7), 'the shop it broke in, named');
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

    public function testTheOwnersIntervalTakesTheDefaultsPlaceAndAnUnreadableOneLeavesTheDefault(): void
    {
        $intervals = new class implements Intervals {
            /** @var array<string, int> */
            public array $minutes = [];

            /** @var array<string, int> */
            public array $starts = [];

            public bool $broken = false;

            public function minutes(string $key, int $default): int
            {
                if ($this->broken) {
                    throw new \RuntimeException('database down');
                }

                return $this->minutes[$key] ?? $default;
            }

            public function start(string $key, int $default): int
            {
                if ($this->broken) {
                    throw new \RuntimeException('database down');
                }

                return $this->starts[$key] ?? $default;
            }
        };
        $scheduler = new Scheduler($this->container, $this->shops, $this->budget, new Logger('test', [$this->log]), $this->stateFile, $intervals);
        $scheduler->everyMinutes(5, CountingTask::class);
        $scheduler->run();

        self::assertSame(5 * 60, $scheduler->interval(CountingTask::class), 'none set: the schedule\'s');
        $intervals->minutes['counting'] = 30;
        self::assertSame(30 * 60, $scheduler->interval(CountingTask::class));
        self::assertSame(5 * 60, $scheduler->tasks()[CountingTask::class]['interval'], 'the default stays the schedule\'s');

        $this->backdate(CountingTask::class, 10 * 60);
        self::assertSame([], $scheduler->run(), 'ten minutes: not yet, by the owner\'s thirty');
        $this->backdate(CountingTask::class, 30 * 60);
        self::assertSame([CountingTask::class], $scheduler->run());

        $intervals->starts['counting'] = 3;
        self::assertSame(3, $scheduler->start(CountingTask::class), "the owner's start");
        $intervals->starts['counting'] = 1440;
        self::assertSame(0, $scheduler->start(CountingTask::class), 'no minute of a day: the default');
        $intervals->starts['counting'] = -5;
        self::assertSame(0, $scheduler->start(CountingTask::class), 'nor before the day: the default');

        $intervals->broken = true;
        self::assertSame(5 * 60, $scheduler->interval(CountingTask::class), 'unreadable: the work goes on at its default');
        self::assertTrue($this->log->hasWarningThatContains('runs at its default'));
        self::assertSame(0, $scheduler->start(CountingTask::class), 'and from its default start');
        self::assertTrue($this->log->hasWarningThatContains('runs from its default'));
    }

    public function testATaskRunsOnItsGridFromItsStartAndALateRunMovesNothing(): void
    {
        LocalTime::use('Asia/Tehran');
        $scheduler = $this->scheduler()->everyMinutes(10, CountingTask::class, at: '00:03');
        $this->tehran('2026-11-26 23:55');
        $scheduler->run();
        self::assertSame('2026-11-27 00:03', $this->local($scheduler->nextRun(CountingTask::class, Carbon::now()->getTimestamp())), 'every ten minutes from 00:03');

        // The tick of 00:03 came late, at 00:05: it runs then, and the next is still 00:13.
        $this->tehran('2026-11-27 00:05');
        self::assertSame([CountingTask::class], $scheduler->run());
        self::assertSame('2026-11-27 00:13', $this->local($scheduler->nextRun(CountingTask::class, Carbon::now()->getTimestamp())));
        $this->tehran('2026-11-27 00:12:59');
        self::assertSame([], $scheduler->run(), 'not before its moment');
        $this->tehran('2026-11-27 00:13');
        self::assertSame([CountingTask::class], $scheduler->run());
        self::assertSame(3, $this->task->runs, 'at 23:55 (its first), 00:05 and 00:13');
    }

    public function testADailyTaskRunsAtItsStartOnceADay(): void
    {
        LocalTime::use('Asia/Tehran');
        $scheduler = $this->scheduler()->everyMinutes(24 * 60, CountingTask::class);
        $this->tehran('2026-11-26 00:00:20');
        $scheduler->run();

        $this->tehran('2026-11-26 23:59');
        self::assertSame([], $scheduler->run(), "the day's run was at midnight");
        $this->tehran('2026-11-27 00:00');
        self::assertSame([CountingTask::class], $scheduler->run(), "the shop's midnight, not UTC's");
        self::assertSame([], $scheduler->run(), 'once');
        self::assertSame('2026-11-28 00:00', $this->local($scheduler->nextRun(CountingTask::class, Carbon::now()->getTimestamp())));

        // A start moved to a time already past today, the task not run since: once, at the next tick.
        $intervals = new class implements Intervals {
            public function minutes(string $key, int $default): int
            {
                return $default;
            }

            public function start(string $key, int $default): int
            {
                return 9 * 60;
            }
        };
        $moved = new Scheduler($this->container, $this->shops, $this->budget, new Logger('test', [$this->log]), $this->stateFile, $intervals);
        $moved->everyMinutes(24 * 60, CountingTask::class);
        $this->tehran('2026-11-27 15:00');
        self::assertSame([CountingTask::class], $moved->run(), 'its 09:00 came since its last run');
        self::assertSame('2026-11-28 09:00', $this->local($moved->nextRun(CountingTask::class, Carbon::now()->getTimestamp())));
    }

    public function testAGridThatDoesNotFitADayStartsAgainAtItsStartAndALongerOneCountsFromASaturday(): void
    {
        $tehran = new \DateTimeZone('Asia/Tehran');
        $at = static fn(string $moment): int => Carbon::parse($moment, 'Asia/Tehran')->getTimestamp();
        $local = static fn(int $moment): string => (new \DateTimeImmutable('@' . $moment))->setTimezone($tehran)->format('Y-m-d H:i');

        // Every 25 minutes from 00:03: the day's last at 23:48, then 00:03 again — never 00:13.
        self::assertSame('2026-11-26 23:48', $local(Scheduler::slot($at('2026-11-27 00:02'), 3, 25, $tehran)));
        self::assertSame('2026-11-27 00:03', $local(Scheduler::slot($at('2026-11-27 00:12'), 3, 25, $tehran)));
        self::assertSame('2026-11-27 00:28', $local(Scheduler::slot($at('2026-11-27 00:30'), 3, 25, $tehran)));

        // Weekly from 03:00: on Saturdays (21 November 2026 was one).
        self::assertSame('2026-11-21 03:00', $local(Scheduler::slot($at('2026-11-26 12:00'), 180, 7 * 24 * 60, $tehran)));
        self::assertSame('2026-11-28 03:00', $local(Scheduler::slot($at('2026-11-28 03:00:30'), 180, 7 * 24 * 60, $tehran)));
    }

    public function testTheNextRunCrossesMidnightOnItsGridAndAWeeklyOneWaitsForSaturday(): void
    {
        LocalTime::use('Asia/Tehran');
        $scheduler = $this->scheduler()->everyMinutes(25, CountingTask::class, at: '00:03');
        $this->tehran('2026-11-26 23:48');
        self::assertSame([CountingTask::class], $scheduler->run());
        self::assertSame('2026-11-27 00:03', $this->local($scheduler->nextRun(CountingTask::class, Carbon::now()->getTimestamp())), 'the day\'s last at 23:48, then 00:03 again — never 00:13');
        $this->tehran('2026-11-26 23:59');
        self::assertSame('2026-11-27 00:03', $this->local($scheduler->nextRun(CountingTask::class, Carbon::now()->getTimestamp() - 11 * 60)));

        unlink($this->stateFile);
        $weekly = $this->scheduler()->everyMinutes(7 * 24 * 60, CountingTask::class, at: '03:00');
        $this->tehran('2026-11-26 12:00');
        self::assertSame([CountingTask::class], $weekly->run(), 'never ran: at once');
        self::assertSame('2026-11-28 03:00', $this->local($weekly->nextRun(CountingTask::class, Carbon::now()->getTimestamp())), 'then on Saturday');
        $this->tehran('2026-11-28 03:00');
        self::assertSame([CountingTask::class], $weekly->run());
        self::assertSame('2026-12-05 03:00', $this->local($weekly->nextRun(CountingTask::class, Carbon::now()->getTimestamp())), 'and the Saturday after');
    }

    public function testAFallBacksRepeatedHourRunsAMinuteOfTheClockAgainOnlyAWholeIntervalLater(): void
    {
        // Berlin's clocks go back from 03:00 CEST to 02:00 CET at 01:00 UTC on 25 October 2026: 02:00 to 02:59 come twice.
        $night = self::ticks('2026-10-24 21:30', '2026-10-25 03:00');
        self::assertSame(['2026-10-24 21:30', '2026-10-25 00:30'], $this->ranAt('Europe/Berlin', 24 * 60, '02:30', $night), 'a daily 02:30 at the first (CEST), never the second (01:30 UTC)');
        self::assertSame('2026-10-26 02:30', $this->local($this->lastScheduler->nextRun(CountingTask::class, Carbon::parse('2026-10-25 00:30', 'UTC')->getTimestamp()), 'Europe/Berlin'), 'next: the day after, never the repeat');
        self::assertSame(['2026-10-24 21:30', '2026-10-24 22:00'], $this->ranAt('Europe/Berlin', 24 * 60, '00:00', $night), 'a daily midnight not again when the clock goes back');
        self::assertSame(['2026-10-24 21:30', '2026-10-25 02:00'], $this->ranAt('Europe/Berlin', 24 * 60, '03:00', $night), 'a daily 03:00 once, at 03:00 CET');
        self::assertSame(['2026-10-24 23:45', '2026-10-25 00:00', '2026-10-25 00:30', '2026-10-25 01:00', '2026-10-25 01:30', '2026-10-25 02:00'], $this->ranAt('Europe/Berlin', 30, '00:00', self::ticks('2026-10-24 23:45', '2026-10-25 02:00')), 'every half hour runs through it');
        self::assertSame(['2026-10-24 21:30', '2026-10-24 22:00', '2026-10-24 23:00', '2026-10-25 00:00', '2026-10-25 01:00', '2026-10-25 02:00', '2026-10-25 03:00'], $this->ranAt('Europe/Berlin', 60, '00:00', $night), 'an hourly one keeps its hour: the second 02:00 is an hour after the first');
        self::assertSame(['2026-10-24 21:30', '2026-10-24 22:00', '2026-10-25 00:00', '2026-10-25 03:00'], $this->ranAt('Europe/Berlin', 120, '00:00', $night), 'every two hours: the second 02:00 only an hour after the first, so 04:00 CET next');

        // Its tick of 02:30 CEST late by a minute and a half: the second 02:30 is still the same moment.
        $late = ['2026-10-24 21:30', '2026-10-25 00:31:30', ...self::ticks('2026-10-25 00:32', '2026-10-25 03:00')];
        self::assertSame(['2026-10-24 21:30', '2026-10-25 00:31:30'], $this->ranAt('Europe/Berlin', 24 * 60, '02:30', $late));

        // A task before it taking 25 seconds a tick, the ticks at :40: the daily 02:59's turn comes after the change, yet
        // its time is the tick's (02:59:40 CEST), so the second 02:59 is not taken for a new moment.
        $busy = self::ticks('2026-10-24 22:00', '2026-10-25 03:00', 40);
        self::assertSame(['2026-10-24 22:00:40', '2026-10-25 00:59:40'], $this->ranAt('Europe/Berlin', 24 * 60, '02:59', $busy, busy: true));
        self::assertSame(Carbon::parse('2026-10-25 00:59:40', 'UTC')->getTimestamp(), $this->lastScheduler->lastRuns()[CountingTask::class]->getTimestamp(), 'stamped with the tick\'s time');

        // New York's go back from 02:00 EDT to 01:00 EST at 06:00 UTC on 1 November 2026.
        self::assertSame(['2026-11-01 04:30', '2026-11-01 05:30'], $this->ranAt('America/New_York', 24 * 60, '01:30', self::ticks('2026-11-01 04:30', '2026-11-01 08:00')), 'a daily 01:30 at 01:30 EDT, never 01:30 EST');
    }

    public function testASpringForwardRunsAMomentItJumpsOverOnceAtTheFirstMinuteAfterIt(): void
    {
        // Berlin's clocks go on from 02:00 CET to 03:00 CEST at 01:00 UTC on 29 March 2026: 02:30 never comes.
        self::assertSame(['2026-03-28 23:00', '2026-03-29 01:00'], $this->ranAt('Europe/Berlin', 24 * 60, '02:30', self::ticks('2026-03-28 23:00', '2026-03-29 04:00')), 'a daily 02:30 at 03:00 CEST, once');

        Carbon::setTestNow(Carbon::parse('2026-03-29 00:00', 'UTC'));
        $before = Carbon::parse('2026-03-28 01:30', 'UTC')->getTimestamp();
        self::assertSame('2026-03-29 03:00', $this->local($this->lastScheduler->nextRun(CountingTask::class, $before), 'Europe/Berlin'), 'said so beforehand');
        Carbon::setTestNow(Carbon::parse('2026-03-29 01:05', 'UTC'));
        self::assertSame('2026-03-30 02:30', $this->local($this->lastScheduler->nextRun(CountingTask::class, Carbon::parse('2026-03-29 01:00', 'UTC')->getTimestamp()), 'Europe/Berlin'), 'then 02:30 CEST the day after');
    }

    public function testATimeOfDayIsReadAndWrittenAsHoursAndMinutes(): void
    {
        self::assertSame([3, 1439, 0], [Scheduler::minuteOf('00:03'), Scheduler::minuteOf('23:59'), Scheduler::minuteOf('00:00')]);
        self::assertSame([null, null, null], [Scheduler::minuteOf('24:00'), Scheduler::minuteOf('3:05'), Scheduler::minuteOf('')]);
        self::assertSame(['00:03', '23:59'], [Scheduler::clock(3), Scheduler::clock(1439)]);
        $this->expectException(\LogicException::class);
        $this->scheduler()->everyMinutes(10, CountingTask::class, at: '25:00');
    }

    public function testATaskIsKeyedByItsClassAndItsLastRunIsKept(): void
    {
        self::assertSame('counting', Scheduler::key(CountingTask::class));
        self::assertSame('sync_subscriptions', Scheduler::key('App\\Modules\\Subscriptions\\Tasks\\SyncSubscriptionsTask'));
        self::assertSame('counting', $this->scheduler()->everyMinutes(1, CountingTask::class)->tasks()[CountingTask::class]['key']);

        $scheduler = $this->scheduler()->everyMinutes(1, CountingTask::class)->everyMinutes(1, FailingTask::class);
        self::assertCount(0, $scheduler->lastRuns(), 'nothing ran yet');
        $scheduler->run();
        self::assertSame([CountingTask::class, FailingTask::class], array_keys($scheduler->lastRuns()), 'a task that failed ran too: its time was written first');
        self::assertEqualsWithDelta(Carbon::now()->getTimestamp(), $scheduler->lastRuns()[CountingTask::class]->getTimestamp(), 2);
    }

    private function scheduler(): Scheduler
    {
        return new Scheduler($this->container, $this->shops, $this->budget, new Logger('test', [$this->log]), $this->stateFile);
    }

    /** The clock held at `$moment` on Tehran's. */
    private function tehran(string $moment): void
    {
        Carbon::setTestNow(Carbon::parse($moment, 'Asia/Tehran')->utc());
    }

    /** A moment on Tehran's clock (or `$zone`'s), to the minute. */
    private function local(\DateTimeImmutable $moment, string $zone = 'Asia/Tehran'): string
    {
        return $moment->setTimezone(new \DateTimeZone($zone))->format('Y-m-d H:i');
    }

    /**
     * Of the ticks `$ticks` (UTC, "Y-m-d H:i" or with seconds, in order), those a task every `$minutes` from `$at` on
     * `$zone`'s clock ran at — never run before the first; with `$busy`, after a task every minute that takes 25
     * seconds of the clock. Its scheduler is kept in `lastScheduler`.
     *
     * @param list<string> $ticks
     * @return list<string>
     */
    private function ranAt(string $zone, int $minutes, string $at, array $ticks, bool $busy = false): array
    {
        LocalTime::use($zone);
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
        $this->lastScheduler = $this->scheduler();
        if ($busy) {
            $slow = new class implements Task {
                public function run(): void
                {
                    Carbon::setTestNow(Carbon::now()->addSeconds(25));
                }
            };
            $this->container->set($slow::class, $slow);
            $this->lastScheduler->everyMinutes(1, $slow::class);
        }
        $this->lastScheduler->everyMinutes($minutes, CountingTask::class, at: $at);
        $ran = [];
        foreach ($ticks as $tick) {
            Carbon::setTestNow(Carbon::parse($tick, 'UTC'));
            if (in_array(CountingTask::class, $this->lastScheduler->run(), true)) {
                $ran[] = $tick;
            }
        }

        return $ran;
    }

    /**
     * Every minute from `$from` to `$to` (UTC), `$second` seconds into it, as ranAt() takes them.
     *
     * @return list<string>
     */
    private static function ticks(string $from, string $to, int $second = 0): array
    {
        $ticks = [];
        for ($at = Carbon::parse($from, 'UTC')->addSeconds($second); $at->lte(Carbon::parse($to, 'UTC')); $at->addMinute()) {
            $ticks[] = $at->format($second === 0 ? 'Y-m-d H:i' : 'Y-m-d H:i:s');
        }

        return $ticks;
    }

    /** Pretend the task last ran `$seconds` ago. */
    private function backdate(string $task, int $seconds): void
    {
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        $state[$task] = Carbon::now()->getTimestamp() - $seconds;
        file_put_contents($this->stateFile, json_encode($state));
    }
}
