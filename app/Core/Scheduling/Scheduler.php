<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

use App\Core\Support\FileLock;
use App\Core\Support\Files;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Interval based scheduler, ticked every minute from outside — a cron's `schedule:run`, the /cron address, or bot:poll
 * while it polls. When each task last ran is kept in a small JSON file (the container's `schedule.state`), so it needs no
 * queue and no daemon.
 *
 * A task is a shop's (`eachBot`: its receipts, renewals, reminders, broadcasts, report group — run once in every shop,
 * the main one first) or the shop's as a whole (its servers' sync and grants — run across every shop at once): Shops
 * says what the shops are. One run has RUN_SECONDS for the work that goes on as long as it may, shared out as it goes
 * (Budget): each turn — a task, or a shop's task in one shop — gets what is left over the turns still to come.
 */
final class Scheduler
{
    /** The time one run shares out: under the minute between two ticks. */
    public const RUN_SECONDS = 45;

    private const LAST_RUN_KEY = '_last_run';

    /** @var array<string, array{task: class-string<Task>, interval: int, eachBot: bool}> */
    private array $tasks = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Shops $shops,
        private readonly Budget $budget,
        private readonly LoggerInterface $logger,
        private readonly string $stateFile,
    ) {}

    /**
     * @param class-string<Task> $task
     * @param bool $eachBot A shop's task, run in every shop in turn; otherwise across all of them at once
     */
    public function everyMinutes(int $minutes, string $task, bool $eachBot = false): self
    {
        $this->tasks[$task] = ['task' => $task, 'interval' => max(1, $minutes) * 60, 'eachBot' => $eachBot];

        return $this;
    }

    /** @return array<string, array{task: class-string<Task>, interval: int, eachBot: bool}> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    /** When a run last went through, whatever ran in it; null when none ever did. */
    public function lastRunAt(): ?\DateTimeImmutable
    {
        $timestamp = (int) ($this->loadState()[self::LAST_RUN_KEY] ?? 0);

        return $timestamp > 0 ? (new \DateTimeImmutable())->setTimestamp($timestamp) : null;
    }

    /**
     * Run every task whose interval has passed (every one with `$force`). A task that fails is logged and keeps no
     * other from running; a shop's task failing in one shop keeps it from none of the others.
     *
     * One run at a time, whoever starts it: a run that outlasts its minute (a slow panel) is not joined by a second
     * doing the same work — the second returns. A task's time is written before it runs, so one that dies on the way
     * (memory, a killed request) waits its interval like any other instead of running again every minute.
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
        $state = $this->loadState();
        $due = array_filter($this->tasks, static fn(array $task): bool => $force || time() - (int) ($state[$task['task']] ?? 0) >= $task['interval']);
        $shops = $this->shopsFor($due);
        if ($shops === null) {
            $due = array_filter($due, static fn(array $task): bool => !$task['eachBot']);
            $shops = [];
        }

        $deadline = microtime(true) + self::RUN_SECONDS;
        $turns = array_sum(array_map(static fn(array $task): int => $task['eachBot'] ? count($shops) : 1, $due));
        $ran = [];

        foreach ($due as $name => $definition) {
            $state[$name] = time();
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

            foreach ($shops as $shop) {
                $this->budget->turn(($deadline - microtime(true)) / max(1, $turns--));
                $this->attempt($name, $shop, fn() => $this->shops->in($shop, static fn() => $task->run()));
            }
            $ran[] = $name;
        }

        $state[self::LAST_RUN_KEY] = time();
        $this->saveState($state);

        return $ran;
    }

    /**
     * The shops a shop's task due now runs in; null when they cannot be read (the database is down): the shops' tasks
     * then wait for the next tick, unmarked.
     *
     * @param array<string, array{task: class-string<Task>, interval: int, eachBot: bool}> $due
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
     * The run's lock; null when there is nowhere to keep one (storage not writable) — the run goes ahead then, since a
     * shop whose tasks never run is worse off than one whose runs may overlap; false when another run holds it.
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
     * Written whole or not at all: half a file would read as "nothing ever ran" and run every task at once.
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
