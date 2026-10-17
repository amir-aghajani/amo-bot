<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

use App\Core\Support\FileLock;
use App\Core\Support\Files;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An interval scheduler ticked every minute from outside (a cron's `schedule:run`, the /cron address, bot:poll). When
 * each task last ran is kept in a JSON file (`schedule.state`): no queue, no daemon. A shop's task (`eachBot`) runs once
 * in every shop; the rest (servers, grants) once across all of them. A run's RUN_SECONDS are shared out as it goes:
 * each turn gets what is left divided by the turns still to come (Budget).
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
     * Run every task whose interval has passed (all with `$force`). A failure is logged and stops nothing else. One run
     * at a time: a second caller returns at once. A task's time is written before it runs, so one that kills the process
     * waits its interval instead of running again every minute.
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

        $state[self::LAST_RUN_KEY] = time();
        $this->saveState($state);

        return $ran;
    }

    /**
     * The shops to run the due shop tasks in; null when they cannot be read (the database is down) — those tasks then
     * wait for the next tick, unmarked.
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
