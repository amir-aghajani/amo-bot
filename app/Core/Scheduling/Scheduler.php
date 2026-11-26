<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

use App\Core\Support\FileLock;
use App\Core\Support\Files;
use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An interval scheduler ticked every minute from outside (a cron's `schedule:run`, the /cron address, bot:poll). When
 * each task last ran is kept in a JSON file (`schedule.state`): no queue, no daemon. A shop's task (`eachBot`) runs once
 * in every shop; the rest (servers, grants) once across all of them. A run's RUN_SECONDS are shared out as it goes:
 * each turn gets what is left divided by the turns still to come (Budget). How often a task runs and the time of day its
 * runs are counted from are the owner's («زمان‌بندی کارها», Intervals), bootstrap/schedule.php's its defaults.
 *
 * A task runs on a grid: its start on the shop's clock and every interval after it (slot()). It is due once the grid's
 * latest moment has come and it has not run since (due()) — so a run held up a few minutes does not move the next one,
 * and a task that missed its moments (Cron stopped, the start moved to a time already past) runs once at the next tick.
 */
final class Scheduler
{
    /** The time one run shares out: under the minute between two ticks. */
    public const RUN_SECONDS = 45;

    /** A time of day as the owner types and reads it, "HH:MM" from 00:00 to 23:59, its hour and minute captured. */
    public const CLOCK_PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    private const LAST_RUN_KEY = '_last_run';

    /** Minutes in a day: a grid of a day or less starts again every day at its start. */
    private const DAY = 1440;

    /** Seconds in a day. */
    private const DAY_SECONDS = 86400;

    /** The day a grid longer than a day counts from: Saturday 3 January 1970, two days after the Unix epoch's. */
    private const FIRST_SATURDAY = 2;

    /** @var array<string, array{task: class-string<Task>, key: string, interval: int, start: int, eachBot: bool}> */
    private array $tasks = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Shops $shops,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
        private readonly string $stateFile,
        /** The owner's intervals; none: each task's default */
        private readonly ?Intervals $intervals = null,
    ) {}

    /**
     * @param int $minutes Its default interval: the owner may set another (Intervals)
     * @param class-string<Task> $task
     * @param bool $eachBot A shop's task, run in every shop in turn; otherwise across all of them at once
     * @param string $at Its default start, "HH:MM" on the shop's clock: the owner may set another (Intervals)
     */
    public function everyMinutes(int $minutes, string $task, bool $eachBot = false, string $at = '00:00'): self
    {
        $start = self::minuteOf($at) ?? throw new \LogicException("{$task} starts at \"{$at}\", which is no time of day.");
        $this->tasks[$task] = ['task' => $task, 'key' => self::key($task), 'interval' => max(1, $minutes) * 60, 'start' => $start, 'eachBot' => $eachBot];

        return $this;
    }

    /**
     * The tasks in the order a run takes them, each with its key, its default interval in seconds and its default start
     * (a minute of the day).
     *
     * @return array<string, array{task: class-string<Task>, key: string, interval: int, start: int, eachBot: bool}>
     */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /**
     * A task's name where the owner sets it: its class's own, without «Task», in snake case (SyncSubscriptionsTask:
     * sync_subscriptions).
     *
     * @param class-string<Task> $task
     */
    public static function key(string $task): string
    {
        $name = (string) preg_replace('/Task$/', '', substr($task, (int) strrpos('\\' . $task, '\\')));

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /**
     * Seconds between two runs of a listed task: the owner's interval (Intervals), its default while they set none — or
     * while the settings cannot be read (the database is down): the work goes on at its usual pace.
     *
     * @param class-string<Task> $task
     */
    public function interval(string $task): int
    {
        $definition = $this->tasks[$task] ?? throw new \LogicException("{$task} is not scheduled.");
        if ($this->intervals === null) {
            return $definition['interval'];
        }

        try {
            return max(1, $this->intervals->minutes($definition['key'], intdiv($definition['interval'], 60))) * 60;
        } catch (\Throwable $e) {
            $this->logger->warning('The interval of {task} cannot be read, so it runs at its default: {message}', ['task' => $task, 'message' => $e->getMessage()]);

            return $definition['interval'];
        }
    }

    /**
     * The minute of the shop's day a listed task's runs are counted from: the owner's (Intervals), its default while they
     * set none — or while the settings cannot be read.
     *
     * @param class-string<Task> $task
     */
    public function start(string $task): int
    {
        $definition = $this->tasks[$task] ?? throw new \LogicException("{$task} is not scheduled.");
        if ($this->intervals === null) {
            return $definition['start'];
        }

        try {
            $start = $this->intervals->start($definition['key'], $definition['start']);

            return $start >= 0 && $start < self::DAY ? $start : $definition['start'];
        } catch (\Throwable $e) {
            $this->logger->warning('The start of {task} cannot be read, so it runs from its default: {message}', ['task' => $task, 'message' => $e->getMessage()]);

            return $definition['start'];
        }
    }

    /**
     * When a listed task is next due, after its last run at `$lastRun` (a timestamp): its grid's latest moment while it
     * is due at it — a moment past or now, the scheduler's next tick —, else the first moment to come that it is due at
     * (a repeat of its last run's minute in the hour a fall-back repeats passed over, as a run passes it over).
     *
     * @param class-string<Task> $task
     */
    public function nextRun(string $task, int $lastRun): \DateTimeImmutable
    {
        $minutes = max(1, intdiv($this->interval($task), 60));
        $start = $this->start($task);
        $zone = LocalTime::zone();
        $now = self::now();
        // A last run ahead of the clock (put back since) counts as now, as the next run takes it (runDue()).
        $lastRun = min($lastRun, $now);
        $slot = self::slot($now, $start, $minutes, $zone);
        $next = self::due($lastRun, $slot, $start, $minutes, $zone) ? $slot : self::upcoming($now, $lastRun, $start, $minutes, $zone);

        return (new \DateTimeImmutable())->setTimestamp($next);
    }

    /**
     * The latest moment, at or before `$now` (a timestamp), of the grid that starts at minute `$start` of the day in
     * `$zone` and goes every `$minutes`: a grid of a day or less starts again at its start every day — its start is a
     * run every day, its last of a day the latest that comes before the next start —; a longer one counts from the start
     * on a Saturday (FIRST_SATURDAY). Counted on the shop's wall clock, so at a daylight-saving change: a moment the
     * clock jumps over in spring comes at the first minute after the jump (a daily 02:30 runs at 03:00 that day); a
     * moment of the hour a fall-back repeats comes twice, and due() says whether it runs the second time.
     */
    public static function slot(int $now, int $start, int $minutes, \DateTimeZone $zone): int
    {
        $instants = self::instants(self::latest(self::wall($now, $zone), $start, $minutes), $zone);
        $passed = array_filter($instants, static fn(int $at): bool => $at <= $now);

        return $passed === [] ? $instants[0] : max($passed);
    }

    /** A time of day as the owner types and reads it (CLOCK_PATTERN), as a minute of the day; null for anything else. */
    public static function minuteOf(string $clock): ?int
    {
        if (preg_match(self::CLOCK_PATTERN, $clock, $parts) !== 1) {
            return null;
        }

        return (int) $parts[1] * 60 + (int) $parts[2];
    }

    /** A minute of the day as "HH:MM". */
    public static function clock(int $minute): string
    {
        $minute = (($minute % self::DAY) + self::DAY) % self::DAY;

        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /**
     * When each task last ran — the time of the tick that found it due —, by its class; one that never did is not here.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function lastRuns(): array
    {
        $runs = [];
        foreach ($this->loadState() as $task => $timestamp) {
            if ($task !== self::LAST_RUN_KEY && (int) $timestamp > 0) {
                $runs[$task] = (new \DateTimeImmutable())->setTimestamp((int) $timestamp);
            }
        }

        return $runs;
    }

    /** When a run last went through, whatever ran in it; null when none ever did. */
    public function lastRunAt(): ?\DateTimeImmutable
    {
        $timestamp = (int) ($this->loadState()[self::LAST_RUN_KEY] ?? 0);

        return $timestamp > 0 ? (new \DateTimeImmutable())->setTimestamp($timestamp) : null;
    }

    /**
     * Run every task that is due — its grid's latest moment came since it last ran (slot(), due()) — or all with
     * `$force`. A failure is logged and stops nothing else. One run at a time: a second caller returns at once. A task's
     * time is written before it runs, so one that kills the process waits for its next moment instead of running again
     * every minute.
     *
     * @return list<string> The tasks that ran.
     */
    public function run(bool $force = false): array
    {
        $lock = $this->lock();
        if ($lock === false) {
            return [];
        }

        try {
            return $this->runDue($force);
        } finally {
            $this->budget->turn(null);
            $lock?->release();
        }
    }

    /** @return list<string> */
    private function runDue(bool $force): array
    {
        $now = self::now();
        // A last run ahead of the clock (it was put back) counts as now, kept so: the task waits for its next moment,
        // neither for the clock to catch up nor running at once.
        $state = array_map(static fn(mixed $at): int => min((int) $at, $now), $this->loadState());
        $zone = LocalTime::zone();
        $due = array_filter($this->tasks, function (array $task) use ($force, $state, $now, $zone): bool {
            if ($force) {
                return true;
            }
            $start = $this->start($task['task']);
            $minutes = max(1, intdiv($this->interval($task['task']), 60));

            return self::due((int) ($state[$task['task']] ?? 0), self::slot($now, $start, $minutes, $zone), $start, $minutes, $zone);
        });
        $shops = $this->shopsFor($due);
        if ($shops === null) {
            $due = array_filter($due, static fn(array $task): bool => !$task['eachBot']);
            $shops = [];
        }

        $deadline = microtime(true) + self::RUN_SECONDS;
        $turns = array_sum(array_map(static fn(array $task): int => $task['eachBot'] ? count($shops) : 1, $due));
        $ran = [];

        foreach ($due as $name => $definition) {
            // The tick's time it was found due at, not its turn's: a turn after a fall-back's change would read as the
            // repeated hour's and let its moment run again (due()).
            $state[$name] = $now;
            $this->saveState($state);

            $task = $this->task($name, $definition['task']);
            if ($task === null) {
                $turns -= $definition['eachBot'] ? count($shops) : 1;
                continue;
            }

            if (!$definition['eachBot']) {
                $this->budget->turn(($deadline - microtime(true)) / max(1, $turns--));
                if ($this->attempt($name, null, fn() => $this->shops->everywhere(static fn() => $task->run()))) {
                    $ran[] = $name;
                }
                continue;
            }

            // A shop's task ran once it went through in a shop; one that failed in every shop did not.
            $wentThrough = false;
            foreach ($shops as $shop) {
                $this->budget->turn(($deadline - microtime(true)) / max(1, $turns--));
                $wentThrough = $this->attempt($name, $shop, fn() => $this->shops->in($shop, static fn() => $task->run())) || $wentThrough;
            }
            if ($wentThrough) {
                $ran[] = $name;
            }
        }

        $state[self::LAST_RUN_KEY] = self::now();
        $this->saveState($state);

        return $ran;
    }

    /** Now, as a timestamp — the app's clock, which a test may hold. */
    private static function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * Whether a task that last ran at `$lastRun` is due at its grid's moment `$moment` (slot()): it has not run since,
     * and the moment its last run was for is not the same minute of the shop's clock less than an interval of real time
     * before — which only the hour a fall-back repeats shows. So that night a task every hour or more often keeps
     * running through it (an hourly one keeps its hour), and one with a longer interval runs its moment once: a daily
     * 02:30 at the first 02:30.
     */
    private static function due(int $lastRun, int $moment, int $start, int $minutes, \DateTimeZone $zone): bool
    {
        if ($lastRun >= $moment) {
            return false;
        }
        // The moment it ran for, not the minute it ran at: a run that came late is still its moment's.
        $ranFor = self::slot($lastRun, $start, $minutes, $zone);

        return $moment - $ranFor >= $minutes * 60 || self::minuteText($moment, $zone) !== self::minuteText($ranFor, $zone);
    }

    /**
     * The first moment after `$now` the grid makes a task that last ran at `$lastRun` due (due()). Its moments are
     * taken in the clock's order from far enough back to take in the hour a coming fall-back repeats; the one that
     * comes first is kept.
     */
    private static function upcoming(int $now, int $lastRun, int $start, int $minutes, \DateTimeZone $zone): int
    {
        $ahead = $now + 2 * ($minutes * 60 + self::DAY_SECONDS);
        $offset = $zone->getOffset(new \DateTimeImmutable('@' . $now));
        $offsets = array_column($zone->getTransitions($now - self::DAY_SECONDS, $ahead) ?: [], 'offset') ?: [$offset];
        $moment = self::latest(self::wall($now, $zone) - max(0, $offset - min($offsets)), $start, $minutes);
        $next = null;
        // A moment comes no earlier than its wall time less the zone's largest offset: past the one kept, stop.
        for ($step = 0; $step < 1000 && ($next === null || $moment - max($offsets) <= $next); $step++) {
            $moment = self::latest($moment + $minutes * 60, $start, $minutes);
            foreach (self::instants($moment, $zone) as $at) {
                if ($at > $now && self::due($lastRun, $at, $start, $minutes, $zone)) {
                    $next = min($next ?? $at, $at);
                    break;
                }
            }
        }

        return $next ?? $now + $minutes * 60;
    }

    /** `$at` (a timestamp) on `$zone`'s wall clock, to the minute, as seconds since 1970-01-01 00:00 on that clock. */
    private static function wall(int $at, \DateTimeZone $zone): int
    {
        $wall = $at + $zone->getOffset(new \DateTimeImmutable('@' . $at));

        return $wall - (($wall % 60) + 60) % 60;
    }

    /** The grid's latest moment at or before the wall time `$wall` (wall()), as a wall time too. */
    private static function latest(int $wall, int $start, int $minutes): int
    {
        $minutes = max(1, $minutes);
        $day = (int) floor($wall / self::DAY_SECONDS);
        $ofDay = intdiv($wall - $day * self::DAY_SECONDS, 60);
        $back = $minutes <= self::DAY
            ? (($ofDay - $start + self::DAY) % self::DAY) % $minutes
            : (((($day - self::FIRST_SATURDAY) * self::DAY + $ofDay - $start) % $minutes) + $minutes) % $minutes;

        return $wall - $back * 60;
    }

    /**
     * The timestamps at which `$zone`'s clock reads the wall time `$wall` (wall()), in order: one; two in the hour a
     * fall-back repeats; in a spring-forward's gap, a time the clock never reads, the moment of the jump — the first
     * minute after the gap.
     *
     * @return non-empty-list<int>
     */
    private static function instants(int $wall, \DateTimeZone $zone): array
    {
        // Each period in force around it, from its change (the first: from where the list begins) to the next.
        $periods = $zone->getTransitions($wall - 2 * self::DAY_SECONDS, $wall + 2 * self::DAY_SECONDS) ?: [];
        $found = [];
        foreach ($periods as $index => $period) {
            $at = $wall - $period['offset'];
            if ($at >= $period['ts'] && $at < ($periods[$index + 1]['ts'] ?? PHP_INT_MAX)) {
                $found[] = $at;
            }
        }
        if ($found !== []) {
            return $found;
        }
        foreach ($periods as $index => $period) {
            if ($index > 0 && $wall >= $period['ts'] + $periods[$index - 1]['offset'] && $wall < $period['ts'] + $period['offset']) {
                return [$period['ts']];
            }
        }

        return [$wall - $zone->getOffset(new \DateTimeImmutable('@' . $wall))];
    }

    /** `$at` (a timestamp) to the minute on `$zone`'s clock, "Y-m-d H:i". */
    private static function minuteText(int $at, \DateTimeZone $zone): string
    {
        return (new \DateTimeImmutable('@' . $at))->setTimezone($zone)->format('Y-m-d H:i');
    }

    /**
     * The shops to run the due shop tasks in; null when they cannot be read (the database is down) — those tasks then
     * wait for the next tick, unmarked.
     *
     * @param array<string, array{task: class-string<Task>, key: string, interval: int, start: int, eachBot: bool}> $due
     * @return list<int>|null
     */
    private function shopsFor(array $due): ?array
    {
        if (array_filter($due, static fn(array $task): bool => $task['eachBot']) === []) {
            return [];
        }

        try {
            return $this->shops->ids();
        } catch (\Throwable $e) {
            $this->logger->error('The scheduler cannot read the shops: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }

    /** @param class-string<Task> $class */
    private function task(string $name, string $class): ?Task
    {
        try {
            return $this->container->get($class);
        } catch (\Throwable $e) {
            $this->logger->error('Scheduled task {task} cannot be made: {message}', ['task' => $name, 'message' => $e->getMessage(), 'exception' => $e]);

            return null;
        }
    }

    /** @param \Closure(): void $work */
    private function attempt(string $task, ?int $shop, \Closure $work): bool
    {
        try {
            $work();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error($shop === null ? 'Scheduled task {task} failed: {message}' : 'Scheduled task {task} failed in shop #{shop}: {message}', [
                'task' => $task,
                'shop' => $shop,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * The run's lock; false when another run holds it; null when there is nowhere to keep one — the run goes ahead,
     * since tasks that never run are worse than runs that may overlap.
     */
    private function lock(): FileLock|false|null
    {
        try {
            return FileLock::take($this->stateFile . '.lock') ?? false;
        } catch (\RuntimeException $e) {
            $this->logger->warning('The scheduler runs without its lock: {message}', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array<string, int> */
    private function loadState(): array
    {
        $decoded = json_decode((string) Files::read($this->stateFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Written atomically: half a file would read as "nothing ever ran" and run every task at once.
     *
     * @param array<string, int> $state
     */
    private function saveState(array $state): void
    {
        try {
            Files::writeAtomically($this->stateFile, (string) json_encode($state, JSON_PRETTY_PRINT));
        } catch (\RuntimeException $e) {
            $this->logger->warning('The scheduler cannot keep when its tasks ran, so they run again at the next tick: {message}', ['message' => $e->getMessage()]);
        }
    }
}
