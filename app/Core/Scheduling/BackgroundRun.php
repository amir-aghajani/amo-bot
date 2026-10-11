<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * A scheduler run in a process of its own (`bin/console schedule:run`), for bot:poll: run inline, the bots would answer
 * nobody for up to Scheduler::RUN_SECONDS while the tasks talk to panels. One run at a time; its output is read and its
 * process reaped once it ends.
 *
 * Only the CLI can start one (under a web server PHP_BINARY is php-cgi or php-fpm). Where proc_open is disabled, refused,
 * or the PHP does not run (exit code 126 or 127), failure() says why and none is tried again: the poller runs the
 * scheduler inline from then on.
 */
final class BackgroundRun
{
    /** Exit codes of a process whose program could not be run. */
    private const NOT_RUN = [126, 127];

    /** @var resource|null The run under way */
    private $process = null;

    /** @var resource|null Where it writes (a temporary file: a pipe left unread would stall a run that says much) */
    private $output = null;

    /** Why no run can be started here; null while they can. */
    private ?string $failure = null;

    /** @param list<string> $command The run: PHP and the console's schedule:run (the container's `schedule.background`) */
    public function __construct(private readonly array $command) {}

    /** Whether this PHP may start a run at all: the CLI, with proc_open allowed. */
    public static function available(): bool
    {
        return PHP_SAPI === 'cli' && function_exists('proc_open');
    }

    /** Whether a run is under way: started, and not read since it ended (finished()). */
    public function running(): bool
    {
        return $this->process !== null;
    }

    /**
     * Start a run; false while one is under way, or when none could be started — then never again (failure() says why)
     * and the caller runs the scheduler itself.
     */
    public function start(): bool
    {
        if ($this->process !== null || $this->failure !== null) {
            return false;
        }

        // What PHP says as it refuses is the reason, not a warning to print into the poller's console.
        $refusal = null;
        $pipes = [];
        set_error_handler(static function (int $type, string $message) use (&$refusal): bool {
            $refusal = $message;

            return true;
        });
        try {
            $output = tmpfile();
            $process = $output === false ? false : proc_open($this->command, [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($process) || $output === false) {
            if (is_resource($output)) {
                fclose($output);
            }
            $this->failure = $refusal ?? 'a process could not be started';

            return false;
        }

        fclose($pipes[0]);
        [$this->process, $this->output] = [$process, $output];

        return true;
    }

    /**
     * The run that ended since the last call — exit code and output — after which another may start; null while one
     * runs or none ended. A run whose PHP could not run stops all further runs (failure()).
     *
     * @return array{code: int, output: string}|null
     */
    public function finished(): ?array
    {
        if ($this->process === null || $this->output === null) {
            return null;
        }
        // The status carries the exit code the first time it says the run is over, and only then.
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return null;
        }

        proc_close($this->process);
        rewind($this->output);
        $output = (string) stream_get_contents($this->output);
        fclose($this->output);
        [$this->process, $this->output] = [null, null];
        if (in_array($status['exitcode'], self::NOT_RUN, true)) {
            $this->failure = sprintf('its PHP (%s) did not run: exit code %d', $this->command[0] ?? '', $status['exitcode']);
        }

        return ['code' => $status['exitcode'], 'output' => $output];
    }

    /** Why no run can be started here any more; null while they can. */
    public function failure(): ?string
    {
        return $this->failure;
    }
}
