<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\BotPollCommand;
use App\Core\Scheduling\BackgroundRun;
use App\Core\Scheduling\Budget;
use App\Core\Scheduling\Scheduler;
use App\Core\Support\FileLock;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Polling\Poller;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Telegram\Services\BotHealth;
use App\Modules\Telegram\Services\BotLifecycle;
use App\Modules\Users\Models\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\BotTestCase;
use Tests\Fakes\CountingTask;
use Tests\Fakes\ListedShops;
use Tests\Support\FakeTelegram;

/**
 * bot:poll — one process polls every bot the shop runs, each taken up with its webhook taken down and its identity
 * asked, each batch served in its bot's own shop and then that bot's report group sent what it queued; a bot Telegram
 * refuses waits with the reason on its row, the main bot's updates taken elsewhere end the poller; it is the shop's
 * timer while it runs (unless a real cron is), reads the settings afresh, and runs alone.
 */
final class BotPollerTest extends BotTestCase
{
    private const AGENT_CUSTOMER = 7373;

    public function testEveryBotIsPolledAndEachBatchServedInItsOwnShop(): void
    {
        $agentBot = $this->agentBot();
        $this->answer(main: [$this->message('/start')->raw], agent: [$this->message('/start', [], self::AGENT_CUSTOMER)->raw]);

        [$ran, $output] = $this->poll();

        self::assertTrue($ran);
        self::assertStringContainsString('Polling @amo_bot (the main bot).', $output);
        self::assertStringContainsString("Polling @agent_shop_bot (agent bot #{$agentBot->id}).", $output);

        $customers = CurrentBot::everywhere(static fn() => User::query()->get()->mapWithKeys(static fn(User $user): array => [$user->telegram_id => $user->bot_id])->all());
        self::assertSame(Bot::MAIN, $customers[self::CHAT], 'the main bot\'s customer, in its shop');
        self::assertSame($agentBot->id, $customers[self::AGENT_CUSTOMER], 'the agent\'s customer, in theirs');

        $speakers = [];
        foreach ($this->telegram()->calls() as $i => $method) {
            if ($method === 'sendMessage') {
                $speakers[$this->telegram()->params($i)['chat_id']][] = $this->telegram()->tokenOf($i);
            }
        }
        self::assertSame([FakeTelegram::TOKEN], array_unique($speakers[(string) self::CHAT] ?? []), 'the main bot\'s customer answered by it');
        self::assertSame([FakeTelegram::AGENT_TOKEN], array_unique($speakers[(string) self::AGENT_CUSTOMER] ?? []), 'the agent\'s, by theirs');
        self::assertNotNull(CurrentBot::run($agentBot, fn() => $this->service(BotState::class)->heartbeatAt()), 'the dashboard sees the agent\'s bot polled');
    }

    public function testABotTakenUpHasItsWebhookTakenDownAndItsIdentityAsked(): void
    {
        $agentBot = $this->agentBot();
        $this->answer(main: [], agent: []);
        $this->telegram()->on('getMe', static fn(array $params, string $token): array => $token === FakeTelegram::TOKEN
            ? ['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'username' => 'amo_bot', 'first_name' => 'AmoBot']
            : ['id' => 777000, 'is_bot' => true, 'username' => 'renamed_shop_bot', 'first_name' => 'فروشگاه تازه']);

        $this->poll(dropPending: true);

        $taken = [];
        foreach ($this->telegram()->calls() as $i => $method) {
            if ($method === 'deleteWebhook') {
                $taken[$this->telegram()->tokenOf($i)] = $this->telegram()->params($i)['drop_pending_updates'] ?? null;
            }
        }
        self::assertSame([FakeTelegram::TOKEN => 'true', FakeTelegram::AGENT_TOKEN => 'true'], $taken, 'polling needs the webhook gone; --drop-pending skips what queued meanwhile');
        self::assertSame(['renamed_shop_bot', 'فروشگاه تازه'], [$agentBot->refresh()->username, $agentBot->title], 'the agent\'s bot as Telegram knows it now');
    }

    public function testABatchIsServedThenWhatItsBotsReportGroupHasQueued(): void
    {
        $agentBot = $this->agentBot();
        $group = -1009876543210;
        $threads = CurrentBot::run($agentBot, fn(): array => $this->reportGroup($group));
        $this->answer(main: [], agent: [$this->message('/start', [], self::AGENT_CUSTOMER)->raw]);

        $this->poll();

        $answered = $reported = null;
        foreach ($this->telegram()->calls() as $i => $method) {
            $chat = $method === 'sendMessage' ? $this->telegram()->params($i)['chat_id'] : null;
            $answered ??= $chat === (string) self::AGENT_CUSTOMER ? $i : null;
            $reported ??= $chat === (string) $group ? $i : null;
        }
        self::assertNotNull($answered, 'the agent\'s newcomer was greeted');
        self::assertNotNull($reported, 'and the newcomer reported in the agent\'s own group');
        self::assertGreaterThan($answered, $reported, 'once the batch was served');
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf($reported), 'by the agent\'s bot');
        self::assertSame((string) $threads[Topic::Users->value], $this->telegram()->params($reported)['message_thread_id']);
    }

    public function testAnAgentsBotTelegramRefusesWaitsWithTheReasonOnItsRow(): void
    {
        $agentBot = $this->agentBot();
        $this->answer(main: [], agent: FakeTelegram::error(401, 'Unauthorized'));

        [$ran] = $this->poll();

        self::assertTrue($ran, 'the main bot is still polled');
        self::assertSame(BotHealth::TOKEN_REFUSED, $agentBot->refresh()->problem, 'the agent reads why their bot is silent');

        $this->answer(main: [], agent: FakeTelegram::error(409, 'Conflict: terminated by other getUpdates request'));
        $this->poll();
        self::assertSame(BotHealth::TOKEN_IN_USE, $agentBot->refresh()->problem);

        $this->answer(main: [], agent: []);
        $this->poll();
        self::assertNull($agentBot->refresh()->problem, 'taken up again, it runs: the reason goes');
    }

    public function testABotTelegramRefusesIsNotAskedAgainBeforeItsWaitIsOver(): void
    {
        // The agent's bot alone (no main token): nothing else to serve while it waits.
        $this->agentBot();
        $this->answer(main: [], agent: FakeTelegram::error(401, 'Unauthorized'));
        $this->config(['telegram.token' => '']);
        $rounds = 0;

        $this->service(Poller::class)->run(new BufferedOutput(), 1, once: false, dropPending: false, schedule: false, stop: static function () use (&$rounds): bool {
            return ++$rounds > 5;
        });

        $asked = array_filter(array_keys($this->telegram()->calls(), 'getUpdates', true), fn(int $i): bool => $this->telegram()->tokenOf($i) === FakeTelegram::AGENT_TOKEN);
        self::assertCount(1, $asked, 'five rounds, one poll: it waits ' . Poller::REFUSED_SECONDS . ' s');
    }

    public function testATokenRefusedAsTheBotIsTakenUpIsNotPolled(): void
    {
        $agentBot = $this->agentBot();
        $this->answer(main: [], agent: []);
        $this->telegram()->on('getMe', static fn(array $params, string $token) => $token === FakeTelegram::AGENT_TOKEN ? FakeTelegram::error(401, 'Unauthorized') : ['id' => FakeTelegram::BOT_ID, 'username' => 'amo_bot']);

        [$ran, $output] = $this->poll();

        self::assertTrue($ran);
        self::assertStringNotContainsString('agent bot', $output);
        self::assertSame(BotHealth::TOKEN_REFUSED, $agentBot->refresh()->problem);
    }

    public function testTheMainBotsUpdatesTakenElsewhereEndThePoller(): void
    {
        $this->answer(main: FakeTelegram::error(409, 'Conflict: terminated by other getUpdates request'), agent: []);

        $tester = $this->pollCommand(['--once' => true, '--no-schedule' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'this poller cannot run while another takes the updates');
        self::assertStringContainsString('409 Conflict', $tester->getDisplay());
    }

    public function testABatchThatCannotBeServedIsLoggedAndTheOtherBotsAreStillServed(): void
    {
        $this->agentBot();
        $logs = $this->logs();
        $this->answer(main: ['not an update'], agent: [$this->message('/start', [], self::AGENT_CUSTOMER)->raw]);

        [$ran] = $this->poll();

        self::assertTrue($ran);
        self::assertTrue($logs->hasErrorThatContains('Serving the updates of bot #' . Bot::MAIN . ' failed'));
        self::assertNotSame([], $this->telegram()->sentTo(self::AGENT_CUSTOMER), 'the agent\'s customer is answered all the same');
    }

    public function testBotsThatCannotBeReadAreLoggedAndThePollerGoesOn(): void
    {
        $this->agentBot();
        $logs = $this->logs();
        $this->answer(main: [], agent: []);

        [$ran] = $this->whileListening('eloquent.retrieved: ' . Bot::class, static fn(): never => throw new \RuntimeException('the database is down'), fn(): array => $this->poll());

        self::assertTrue($ran, 'the poller does not die of it: the list is read again at the next round');
        self::assertTrue($logs->hasErrorThatContains('Could not read the bots to poll: the database is down'));
        self::assertNotContains('getUpdates', $this->telegram()->calls());
    }

    public function testAPollThatFailsOtherwiseIsAskedAgainAfterAPause(): void
    {
        $this->answer(main: FakeTelegram::error(502, 'Bad Gateway'), agent: []);

        [$ran, $output] = $this->poll();

        self::assertTrue($ran);
        self::assertStringContainsString('Polling error (the main bot): Bad Gateway — asking again in 1s', $output);
    }

    public function testThePollerIsTheShopsTimerUnlessACronIsAndReadsTheSettingsAfresh(): void
    {
        $task = new CountingTask();
        $this->swap(CountingTask::class, $task);
        $scheduler = (new Scheduler($this->app()->container(), new ListedShops(), new Budget(), $this->service(LoggerInterface::class), $this->scratchDir() . '/schedule.json'))->everyMinutes(1, CountingTask::class);
        $this->swap(Scheduler::class, $scheduler, Poller::class, BotPollCommand::class);

        $settings = $this->service(Settings::class);
        self::assertNull($settings->get('bot.support_contact'));
        $this->db()->table('settings')->insert(['bot_id' => Bot::MAIN, 'key' => 'bot.support_contact', 'value' => json_encode('@support')]);
        $this->answer(main: [], agent: []);

        self::assertSame(Command::SUCCESS, $this->pollCommand(['--once' => true, '--no-schedule' => true])->getStatusCode());
        self::assertSame(0, $task->runs, 'a real cron runs the scheduler: --no-schedule');
        self::assertSame('@support', $settings->get('bot.support_contact'), 'what the admin saved meanwhile reaches the next update');

        self::assertSame(Command::SUCCESS, $this->pollCommand(['--once' => true])->getStatusCode());
        self::assertSame(1, $task->runs, 'otherwise the poller runs what is due');
    }

    public function testTheSchedulersRunGoesInAProcessOfItsOwnWhileTheBotsArePolled(): void
    {
        $logs = $this->logs();
        $this->answer(main: [], agent: []);
        // A run that takes a moment, says the task it ran — schedule:run's way — and ends badly.
        $run = new BackgroundRun([PHP_BINARY, '-r', 'usleep(300000); echo "  ✔ App\\\\Tasks\\\\Ticked\n"; exit(3);']);
        $poller = $this->app()->container()->make(Poller::class, ['background' => $run]);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $said = '';
        $polls = null;
        $until = microtime(true) + 30;

        $poller->run($output, 1, once: false, dropPending: false, schedule: true, stop: function () use ($output, &$said, &$polls, $until): bool {
            $said .= $output->fetch();
            if (str_contains($said, 'scheduled: Ticked')) {
                $polls = count(array_keys($this->telegram()->calls(), 'getUpdates', true));

                return true;
            }

            return microtime(true) > $until;
        });

        self::assertNotNull($polls, 'what the run did is reported once it is over');
        self::assertGreaterThan(1, $polls, 'the bot is polled again and again while the run goes on');
        self::assertTrue($logs->hasErrorThatContains('A scheduler run ended with code 3'), 'a run that broke is in the log');
    }

    public function testWhereNoRunCanBeStartedThePollerRunsTheSchedulerItself(): void
    {
        $task = new CountingTask();
        $this->swap(CountingTask::class, $task);
        $this->swap(Scheduler::class, (new Scheduler($this->app()->container(), new ListedShops(), new Budget(), $this->service(LoggerInterface::class), $this->scratchDir() . '/schedule.json'))->everyMinutes(1, CountingTask::class));
        $logs = $this->logs();
        $this->answer(main: [], agent: []);
        // A PHP that is not there: proc_open refuses it, or its process cannot run it (the shell's 127).
        $run = new BackgroundRun([dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'no-such-php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''), '-r', '']);
        $poller = $this->app()->container()->make(Poller::class, ['background' => $run]);
        $until = microtime(true) + 30;

        $poller->run(new BufferedOutput(), 1, once: false, dropPending: false, schedule: true, stop: static fn(): bool => $task->runs > 0 || microtime(true) > $until);

        self::assertSame(1, $task->runs, 'the scheduler ran in the poller itself, its minute not lost');
        self::assertNotNull($run->failure());
        self::assertTrue($logs->hasWarningThatContains('The scheduler cannot run in a process of its own'), 'said in the log');
    }

    public function testOnlyOnePollerRunsAtATime(): void
    {
        $lock = FileLock::take((string) $this->app()->container()->get('poller.lock'));
        self::assertNotNull($lock);

        try {
            $tester = $this->pollCommand(['--once' => true, '--no-schedule' => true]);
            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('Another bot:poll process is already running', $tester->getDisplay());
        } finally {
            $lock->release();
        }

        $this->answer(main: [], agent: []);
        self::assertSame(Command::SUCCESS, $this->pollCommand(['--once' => true, '--no-schedule' => true])->getStatusCode(), 'and runs once the other is gone');
    }

    /**
     * `--watch` reloads its worker when the source changes: a file edited, added, deleted, or put back as an older copy —
     * none of which the newest modification time alone would tell when the file is not the newest.
     */
    public function testTheWatchedSourceChangesWithEveryFileEditedAddedDeletedOrRolledBack(): void
    {
        $dir = $this->scratchDir();
        mkdir("{$dir}/app");
        $file = static function (string $name, string $content, int $time) use ($dir): void {
            file_put_contents("{$dir}/{$name}", $content);
            touch("{$dir}/{$name}", $time);
        };
        $file('app/Old.php', '<?php // old', 1_700_000_000);
        $file('app/New.php', '<?php // new', 1_700_000_500);
        $file('config.php', '<?php return [];', 1_700_000_100);
        $watched = ["{$dir}/app", "{$dir}/config.php"];
        $source = BotPollCommand::fingerprint($watched);

        self::assertSame($source, BotPollCommand::fingerprint($watched), 'nothing changed: nothing to reload');
        $file('app/notes.txt', 'not code', 1_700_000_900);
        self::assertSame($source, BotPollCommand::fingerprint($watched), 'nor for a file that is no PHP');

        $file('app/Old.php', '<?php // older', 1_600_000_000);
        self::assertNotSame($source, $rolledBack = BotPollCommand::fingerprint($watched), 'a file rolled back to an older copy');

        unlink("{$dir}/app/Old.php");
        self::assertNotSame($rolledBack, $deleted = BotPollCommand::fingerprint($watched), 'a file deleted');

        $file('app/Added.php', '<?php // added', 1_600_000_000);
        self::assertNotSame($deleted, BotPollCommand::fingerprint($watched), 'a file added, however old');
    }

    public function testWithoutAMainTokenThereIsNothingToPoll(): void
    {
        $this->config(['telegram.token' => '']);

        $tester = $this->pollCommand(['--once' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(BotLifecycle::NO_TOKEN, $tester->getDisplay());
    }

    /**
     * What Telegram answers: who each bot is, and each bot's one batch of updates — a list, or a refusal.
     *
     * @param list<mixed>|\GuzzleHttp\Psr7\Response $main
     * @param list<mixed>|\GuzzleHttp\Psr7\Response $agent
     */
    private function answer(array|\GuzzleHttp\Psr7\Response $main, array|\GuzzleHttp\Psr7\Response $agent): void
    {
        $this->telegram()->on('getMe', static fn(array $params, string $token): array => $token === FakeTelegram::TOKEN
            ? ['id' => FakeTelegram::BOT_ID, 'is_bot' => true, 'username' => 'amo_bot', 'first_name' => 'AmoBot']
            : ['id' => 777000, 'is_bot' => true, 'username' => 'agent_shop_bot', 'first_name' => 'فروشگاه نماینده']);
        $this->telegram()->on('getUpdates', static fn(array $params, string $token): mixed => $token === FakeTelegram::TOKEN ? $main : $agent);
    }

    /** @return array{bool, string} Whether the poller could run (one answer from every bot), and what it said */
    private function poll(bool $dropPending = false): array
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $ran = $this->service(Poller::class)->run($output, 1, once: true, dropPending: $dropPending, schedule: false, stop: static fn(): bool => false);

        return [$ran, $output->fetch()];
    }

    /**
     * bin/console bot:poll with these options, as it ran.
     *
     * @param array<string, mixed> $options
     */
    private function pollCommand(array $options): CommandTester
    {
        $tester = new CommandTester($this->service(BotPollCommand::class));
        $tester->execute($options);

        return $tester;
    }
}
