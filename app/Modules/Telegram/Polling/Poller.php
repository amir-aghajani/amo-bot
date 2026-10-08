<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Polling;

use App\Core\Config\Repository as Config;
use App\Core\Logging\Redact;
use App\Core\Scheduling\BackgroundRun;
use App\Core\Scheduling\Scheduler;
use App\Core\Support\Sleeper;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Services\BotHealth;
use App\Modules\Telegram\Services\BotLifecycle;
use App\Modules\Telegram\Update\Dispatcher;
use App\Modules\Telegram\Update\Update;
use App\Support\LocalTime;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long polling for every bot the shop runs, the main one and each agent's (Bots::serving(), read again every
 * REFRESH_SECONDS: a bot handed over meanwhile is taken up — its webhook taken down, its identity asked — and one
 * switched off is let go). Every bot keeps one poll open at a time, all of them at once (LongPolls); every bot whose
 * poll answered is served — its updates dispatched in its own shop, then what its report group has queued sent — and
 * asked again. A bot whose token Telegram refuses, or whose updates another program takes, waits REFUSED_SECONDS with
 * the reason on its row (BotHealth); the main bot's updates taken elsewhere end the poller, which cannot run then.
 * Anything else is asked again after a pause that grows.
 *
 * The polls only move while the poller waits on them: what it does meanwhile — serving a batch, which may wait on a
 * panel — leaves every other bot's poll open, its answer unread, and the HTTP client counts that time against the poll
 * (BotApi::LONG_POLL_SLACK). So the long work stays out of the loop: while it polls, the process is the shop's timer —
 * the scheduler, about once a minute — through a process of its own (BackgroundRun), and runs it inline only where PHP
 * starts no process. It says it is alive (each bot's heartbeat, which the dashboard reads), and it lives for hours: the
 * settings are read afresh every SETTINGS_SECONDS, so what the admin saved meanwhile reaches the bots — not every
 * batch, which would read them all again for each.
 */
final class Poller
{
    /** How often the list of bots is read again. */
    public const REFRESH_SECONDS = 15;

    /** How often each bot's heartbeat is written (the dashboard says "polling" while it is fresh). */
    public const HEARTBEAT_SECONDS = 20;

    /** How long a bot whose token is refused, or whose updates another program takes, waits before it is asked again. */
    public const REFUSED_SECONDS = 60;

    /** How often the scheduler runs from here. */
    public const TICK_SECONDS = 60;

    /** How often the settings are read again: what the admin saved reaches the bots within this. */
    public const SETTINGS_SECONDS = 2;

    /** The longest pause after failures in a row. */
    private const MAX_BACKOFF = 30;

    /** @var array<int, PolledBot> By bot id */
    private array $polled = [];

    /** Whether the log was told the scheduler runs here, its process of its own refused. */
    private bool $toldRunsHere = false;

    /**
     * @param BackgroundRun|null $background The scheduler's runs in a process of their own (the container's
     *                                       `schedule.background`); null where PHP starts no process — the scheduler runs here
     */
    public function __construct(
        private readonly BotApi $api,
        private readonly LongPolls $polls,
        private readonly Bots $bots,
        private readonly BotLifecycle $lifecycle,
        private readonly BotHealth $health,
        private readonly BotState $state,
        private readonly Dispatcher $dispatcher,
        private readonly ReportSender $reports,
        private readonly Settings $settings,
        private readonly Scheduler $scheduler,
        private readonly ?BackgroundRun $background,
        private readonly Config $config,
        private readonly Sleeper $sleeper,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Poll until `$stop` says so (asked between answers); with `$once`, until every bot answered once.
     *
     * @param int $timeout Seconds a poll waits for updates
     * @param bool $dropPending Skip the updates queued before the start
     * @param bool $schedule Run the scheduler from here (no real cron)
     * @param \Closure(): bool $stop
     * @return bool False when the main bot's updates are taken by another program: this poller cannot run
     */
    public function run(OutputInterface $output, int $timeout, bool $once, bool $dropPending, bool $schedule, \Closure $stop): bool
    {
        $polling = $this->api->withHttp($this->polls->client());
        $allowed = array_values((array) $this->config->get('telegram.allowed_updates', []));
        $this->polled = [];
        $refreshed = null;
        $ticked = null;
        $settings = null;

        $output->writeln(sprintf('Polling every bot (timeout %ds). Press Ctrl+C to stop.', $timeout));

        while (!$stop()) {
            $settings = $this->freshSettings($settings);

            if ($refreshed === null || time() - $refreshed >= self::REFRESH_SECONDS) {
                $refreshed = time();
                $this->refresh($dropPending, $output);
                $dropPending = false;
            }
            if ($schedule) {
                // A run whose PHP could not run did nothing: its minute is still owed, and is run here at once.
                $owed = $this->reportRun($output);
                if ($owed || $ticked === null || time() - $ticked >= self::TICK_SECONDS) {
                    $ticked = time();
                    $this->tick($output);
                }
            }

            foreach ($this->polled as $bot) {
                $this->heartbeat($bot);
                if ($bot->poll === null && $bot->waitUntil <= time() && !($once && $bot->answers > 0)) {
                    $bot->poll = CurrentBot::run($bot->bot, static fn(): PromiseInterface => $polling->getUpdatesAsync($bot->offset, $timeout, $allowed))
                        ->then(static fn(array $updates): array => $updates, static fn(\Throwable $e): \Throwable => $e);
                }
            }
            if ($once && array_filter($this->polled, static fn(PolledBot $bot): bool => $bot->answers === 0) === []) {
                return true;
            }

            foreach ($this->answered() as $bot) {
                $answer = $bot->poll?->wait();
                $bot->poll = null;
                $bot->answers++;

                if ($answer instanceof \Throwable) {
                    if (!$this->failed($bot, $answer, $output)) {
                        return false;
                    }
                    continue;
                }

                $bot->backoff = 1;
                $this->serve($bot, $answer, $output);
            }
        }

        return true;
    }

    /**
     * Every shop's settings read again once SETTINGS_SECONDS went by since `$readAt` (unix seconds), so what the admin
     * saved meanwhile — a text, a keyboard, a rule — reaches the bots, without every batch reading them all again.
     * Returns when they were last read.
     */
    private function freshSettings(?int $readAt): int
    {
        if ($readAt !== null && time() - $readAt < self::SETTINGS_SECONDS) {
            return $readAt;
        }
        $this->settings->refresh();

        return time();
    }

    /**
     * The bots to poll now: every serving one — those kept from before with where they stand, the others taken up
     * (their webhook taken down, their identity asked). An agent's bot whose token changed is another bot; the main
     * bot's comes from config.php, which a running process does not read again (a change restarts the worker, see
     * bot:poll).
     */
    private function refresh(bool $dropPending, OutputInterface $output): void
    {
        try {
            $serving = $this->bots->serving();
        } catch (\Throwable $e) {
            $this->logger->error('Could not read the bots to poll: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return;
        }

        $next = [];
        foreach ($serving as $bot) {
            $key = $bot->isMain() ? 'main' : hash('sha256', (string) $bot->token);
            $kept = $this->polled[$bot->id] ?? null;
            if ($kept !== null && $kept->key === $key) {
                $kept->bot = $bot;
                $next[$bot->id] = $kept;
                continue;
            }

            try {
                $username = CurrentBot::run($bot, function () use ($dropPending): string {
                    $this->lifecycle->disableWebhook($dropPending);

                    return $this->lifecycle->rememberIdentity();
                });
            } catch (TelegramApiException $e) {
                // Asked again at the next refresh, a new token or not.
                $this->health->refused($bot, $e);
                continue;
            }

            $this->health->running($bot);
            $output->writeln(sprintf('Polling <info>@%s</info> (%s).', $username !== '' ? $username : '?', self::name($bot)));
            $next[$bot->id] = new PolledBot($bot, $key);
        }

        foreach (array_diff_key($this->polled, $next) as $id => $gone) {
            $gone->poll?->cancel();
            $output->writeln(sprintf('Stopped polling bot #%d.', $id));
        }

        $this->polled = $next;
    }

    /** Lets the dashboard show the bot as polling while this process is alive. */
    private function heartbeat(PolledBot $bot): void
    {
        if (time() - $bot->heartbeat >= self::HEARTBEAT_SECONDS) {
            CurrentBot::run($bot->bot, fn() => $this->state->heartbeat());
            $bot->heartbeat = time();
        }
    }

    /**
     * Move the polls on until one answers, and every bot whose poll has answered by then — its updates, or what went
     * wrong, are its poll's: bots that answered together are served in the same round, none waiting a round behind
     * another. None when there is nothing to wait for (every bot waiting out a pause, after a second's rest) or
     * REFRESH_SECONDS went by — the loop comes round for the list of bots, the timer and a code change.
     *
     * @return list<PolledBot>
     */
    private function answered(): array
    {
        $open = array_filter($this->polled, static fn(PolledBot $bot): bool => $bot->poll !== null);
        if ($open === []) {
            $this->sleeper->sleep(1);

            return [];
        }

        $deadline = time() + self::REFRESH_SECONDS;
        while (true) {
            $this->polls->tick();
            $answered = array_values(array_filter($open, static fn(PolledBot $bot): bool => $bot->poll?->getState() !== PromiseInterface::PENDING));
            if ($answered !== [] || time() >= $deadline) {
                return $answered;
            }
        }
    }

    /**
     * A poll that failed. False when the poller cannot run: the main bot's updates taken by another program (a second
     * poller, a webhook set elsewhere). An agent's bot refused or taken elsewhere waits REFUSED_SECONDS, its reason on
     * its row; anything else is asked again after a pause that grows.
     */
    private function failed(PolledBot $bot, \Throwable $e, OutputInterface $output): bool
    {
        $refused = $e instanceof TelegramApiException ? $e : null;

        if ($refused?->is(Refusal::Conflict) === true && $bot->bot->isMain()) {
            $output->writeln('<error>Telegram reports another getUpdates client for the main bot (409 Conflict): stop the other bot:poll, or remove its webhook.</error>');

            return false;
        }
        if ($refused !== null && !$bot->bot->isMain() && $this->health->refused($bot->bot, $refused)) {
            $bot->waitUntil = time() + self::REFUSED_SECONDS;

            return true;
        }

        $wait = $refused?->retryAfter() ?? $bot->backoff;
        $output->writeln(sprintf('<comment>Polling error (%s): %s — asking again in %ds</comment>', self::name($bot->bot), Redact::text($e->getMessage()), $wait));
        $bot->waitUntil = time() + $wait;
        $bot->backoff = min($bot->backoff * 2, self::MAX_BACKOFF);

        return true;
    }

    /**
     * One bot's batch, in its shop: each update dispatched (the dispatcher answers its own failures), then what its
     * report group has queued. The offset moves past each update before it is served, so one that brings the process
     * down is not served again. Whatever else fails is logged: the other bots are still served.
     *
     * @param list<array<string, mixed>> $updates
     */
    private function serve(PolledBot $bot, array $updates, OutputInterface $output): void
    {
        try {
            CurrentBot::run($bot->bot, function () use ($bot, $updates, $output): void {
                foreach ($updates as $raw) {
                    $update = new Update($raw);
                    $bot->offset = $update->id() + 1;
                    if ($output->isVerbose()) {
                        // A command is shown with what follows it ("/start ref_…", a deep link's code); other text is not echoed.
                        $command = $update->isCommand() ? ' ' . mb_substr((string) $update->text(), 0, 64) : '';
                        $output->writeln(sprintf('[%s] %s: update %d (%s%s) from %s', self::now(), self::name($bot->bot), $update->id(), $update->type(), $command, $update->fromId() ?? '-'));
                    }
                    $this->dispatcher->dispatch($update);
                }

                $this->reports->flush();
            });
        } catch (\Throwable $e) {
            $this->logger->error('Serving the updates of bot #{bot} failed: {message}', ['bot' => $bot->bot->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * Run what the scheduler has due: in a process of its own, the polls going on meanwhile — none starts while the last
     * is still going —, or here where none can be started (PHP may start none, or the one it started could not run: the
     * log hears it once). The scheduler logs a task that fails and carries on, and nothing here stops the polling.
     */
    private function tick(OutputInterface $output): void
    {
        if ($this->background !== null) {
            if ($this->background->running() || $this->background->start()) {
                return;
            }
            if (!$this->toldRunsHere) {
                $this->toldRunsHere = true;
                $this->logger->warning('The scheduler cannot run in a process of its own ({why}): the poller runs it itself, its bots waiting meanwhile', ['why' => $this->background->failure()]);
            }
        }

        try {
            $ran = $this->scheduler->run();
        } catch (\Throwable $e) {
            $this->logger->error('Scheduler tick failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return;
        }

        self::scheduled($output, $ran);
    }

    /**
     * What a run in its own process did, once it is over: the tasks it ran, and a run that broke, in the log. True when
     * its PHP could not run at all — it did nothing, and runs go no further that way (BackgroundRun::failure()).
     */
    private function reportRun(OutputInterface $output): bool
    {
        $run = $this->background?->finished();
        if ($run === null) {
            return false;
        }
        if ($run['code'] !== 0) {
            $this->logger->error('A scheduler run ended with code {code}: {output}', ['code' => $run['code'], 'output' => mb_substr(trim($run['output']), 0, 2000)]);
        }

        // schedule:run says each task it ran on a line of its own, its class last.
        preg_match_all('/✔\s*(\S+)/u', $run['output'], $tasks);
        self::scheduled($output, $tasks[1]);

        return $this->background?->failure() !== null;
    }

    /** @param list<string> $tasks The classes of the tasks a run ran */
    private static function scheduled(OutputInterface $output, array $tasks): void
    {
        if ($tasks !== [] && $output->isVerbose()) {
            $output->writeln(sprintf('[%s] scheduled: %s', self::now(), implode(', ', array_map(static fn(string $task): string => basename(str_replace('\\', '/', $task)), $tasks))));
        }
    }

    private static function name(Bot $bot): string
    {
        return $bot->isMain() ? 'the main bot' : "agent bot #{$bot->id}";
    }

    private static function now(): string
    {
        return LocalTime::of(new \DateTimeImmutable())->format('H:i:s');
    }
}
