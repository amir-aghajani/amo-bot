<?php

declare(strict_types=1);

namespace App\Core\Scheduling;

/**
 * A scheduler run in a process of its own (`bin/console schedule:run`), started and left to itself: for bot:poll, which
 * is the shop's timer while it polls — and whose bots would answer nobody while a run inline talks to the panels (up to
 * Scheduler::RUN_SECONDS; a panel out of reach alone costs its connect timeout), with every poll left open meanwhile.
 * One run at a time from here (the scheduler's own lock keeps a cron's out of it too); what a run printed is read once
 * it is over, its process reaped then.
 *
 * Only the command line starts one: under a web server PHP_BINARY is the server's PHP (php-cgi, php-fpm), not one that
 * runs a console command — the cron address and the webhooks run the scheduler themselves. Where PHP may start no
 * process at all (proc_open disabled, as shared hosts do) there is none (available()); and one that cannot be started
 * after all — proc_open refused at the time, or a PHP that would not run (its exit code the shell's 126 or 127) — says
 * why (failure()) and is not tried again: the poller runs the scheduler itself from then on.
 */
final class BackgroundRun
{
    /** Exit codes of a process whose program could not be run: the shell's, and an exec PHP's child failed exits with 127. */
    private const NOT_RUN = [126, 127];

    /** @var resource|null The run under way */
    private $process = null;

    /** @var resource|null Where it writes (a temporary file: a pipe left unread would stall a run that says much) */
    private $output = null;

    /** Why no run can be started here; null while they can. */
    private ?string $failure = null;

    /** @param list<string> $command The run: PHP and the console's schedule:run (the container's `schedule.background`) */
    public function __construct(private readonly array $command) {}

    /** Whether this PHP may start a run at all: the command line's, allowed to start a process. */
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
     * Start a run; false while one is under way, and when none could be started — then never again (failure() says why):
     * the caller runs the scheduler itself.
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
     * The run that ended since the last call — its exit code and what it printed —, after which another may start; null
     * while one runs, or when none ended. A run whose PHP could not be run ends the runs from here (failure()).
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

    /** Why no run can be started here (none ever will be from then on); null while they can. */
    public function failure(): ?string
    {
        return $this->failure;
    }
}
