<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Application;
use App\Core\Config\Repository as Config;
use App\Core\Support\FileLock;
use App\Core\Support\Sleeper;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Polling\Poller;
use App\Modules\Telegram\Services\BotLifecycle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long polling (Telegram\Polling\Poller) for local development, where there is no public URL, and for a server without
 * a webhook — one process for every bot, held to one at a time by a lock file (two pollers would take each other's
 * updates).
 *
 * `--watch` runs a supervisor that keeps a worker process alive: the worker ends when the code or config.php on disk
 * changes — a file edited, added, deleted or rolled back to an older copy (fingerprint()); a long-running PHP process
 * would go on serving the classes and the settings it started with — or when it crashes, and the supervisor starts a
 * fresh one.
 */
#[AsCommand(name: 'bot:poll', description: 'Receive updates with long polling for every bot (development / no-webhook mode)')]
final class BotPollCommand extends Command
{
    /** A worker's exit code meaning "the source changed, start me again". */
    private const EXIT_RELOAD = 75;

    /** What a worker watches besides config.php: a change to any of it is a reload. */
    private const WATCHED_PATHS = ['app', 'bootstrap', 'config', 'routes'];

    /** A worker's polls are short, so a change is picked up quickly. */
    private const WORKER_TIMEOUT = 10;

    /** The pause before a crashed worker is started again. */
    private const RESTART_SECONDS = 3;

    /** @param string $lock The container's `poller.lock`: the file only one poller holds */
    public function __construct(
        private readonly Application $app,
        private readonly Poller $poller,
        private readonly BotApi $api,
        private readonly Config $config,
        private readonly Sleeper $sleeper,
        private readonly string $lock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Fetch one batch of updates from every bot and exit')
            ->addOption('drop-pending', null, InputOption::VALUE_NONE, 'Skip updates queued before start')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Supervise: restart the worker when code changes or it crashes')
            ->addOption('worker', null, InputOption::VALUE_NONE, 'Internal: run as a supervised worker')
            ->addOption('no-schedule', null, InputOption::VALUE_NONE, 'Do not run the scheduled tasks from this process (a cron does)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('watch')) {
            return $this->supervise($input, $io);
        }

        if (!$this->api->hasToken()) {
            $io->error(BotLifecycle::NO_TOKEN);

            return Command::FAILURE;
        }

        $lock = FileLock::take($this->lock);
        if ($lock === null) {
            $io->error('Another bot:poll process is already running for this installation.');

            return Command::FAILURE;
        }

        try {
            $worker = (bool) $input->getOption('worker');
            $watched = [...array_map($this->app->basePath(...), self::WATCHED_PATHS), $this->app->configFile()];
            $fingerprint = $worker ? self::fingerprint($watched) : null;
            $reload = false;
            $ran = $this->poller->run(
                $output,
                $worker ? self::WORKER_TIMEOUT : (int) $this->config->get('telegram.poll_timeout', 30),
                once: (bool) $input->getOption('once'),
                dropPending: (bool) $input->getOption('drop-pending'),
                schedule: !$input->getOption('no-schedule'),
                stop: static function () use ($watched, $fingerprint, &$reload): bool {
                    $reload = $fingerprint !== null && self::fingerprint($watched) !== $fingerprint;

                    return $reload;
                },
            );
        } finally {
            $lock->release();
        }

        if ($reload) {
            $io->writeln('<comment>Source changed — reloading the worker.</comment>');

            return self::EXIT_RELOAD;
        }

        return $ran ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Keep a worker alive: started again on a reload (the code changed) and, after a short pause, on a crash. The worker
     * gets the options that shape its run; `--drop-pending` only the first time, or every restart would throw updates
     * away.
     */
    private function supervise(InputInterface $input, SymfonyStyle $io): int
    {
        $console = $_SERVER['argv'][0] ?? 'bin/console';
        $io->writeln('<info>Supervising bot:poll</info> — the worker restarts when the source changes or crashes. Press Ctrl+C to stop.');

        $first = true;
        while (true) {
            $parts = [PHP_BINARY, $console, 'bot:poll', '--worker'];
            if ($first && $input->getOption('drop-pending')) {
                $parts[] = '--drop-pending';
            }
            foreach (['no-schedule', 'once'] as $option) {
                if ($input->getOption($option)) {
                    $parts[] = '--' . $option;
                }
            }
            if ($io->isVerbose()) {
                $parts[] = '-v';
            }
            $first = false;

            $exitCode = 0;
            passthru(implode(' ', array_map('escapeshellarg', $parts)), $exitCode);

            if ($exitCode === self::EXIT_RELOAD) {
                continue;
            }
            if ($exitCode === Command::SUCCESS) {
                return Command::SUCCESS;
            }

            $io->warning(sprintf('Worker exited with code %d; restarting in %ds.', $exitCode, self::RESTART_SECONDS));
            $this->sleeper->sleep(self::RESTART_SECONDS);
        }
    }

    /**
     * The source as it stands, in one string: every PHP file of the watched `$paths` (folders, and files such as
     * config.php) by its name, its size and its modification time — so a file changed, added, deleted or put back as an
     * older copy (a rollback) changes it, which the newest time alone would not tell.
     *
     * @param list<string> $paths Absolute
     */
    public static function fingerprint(array $paths): string
    {
        // PHP keeps a file's last stat: a long-running worker must see the disk as it is now.
        clearstatcache();
        $files = [];
        foreach ($paths as $path) {
            $found = match (true) {
                is_file($path) => [new \SplFileInfo($path)],
                is_dir($path) => new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)),
                default => [],
            };
            foreach ($found as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                try {
                    $files[] = $file->getPathname() . "\0" . $file->getSize() . "\0" . $file->getMTime();
                } catch (\RuntimeException) {
                    // Deleted while the folder was read: it is not there.
                }
            }
        }
        sort($files);

        return hash('sha256', implode("\n", $files));
    }
}
