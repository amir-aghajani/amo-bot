<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\BotInfoCommand;
use App\Console\Commands\BotWebhookDeleteCommand;
use App\Console\Commands\BotWebhookSetCommand;
use App\Core\Config\ConfigFile;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Services\BotHealth;
use App\Modules\Telegram\Services\BotLifecycle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * bot:webhook:set, bot:webhook:delete and bot:info do their work for every bot the shop runs, each as itself — and one
 * bot Telegram refuses keeps it from none of the others, an agent's reason kept on its row (and cleared once the bot is
 * set again), as the poller keeps it.
 */
final class BotCommandsTest extends BotTestCase
{
    public function testEveryBotsWebhookIsSetAtItsAddressUnderAppUrl(): void
    {
        $this->config(['app.url' => 'https://shop.example/shop/']);
        $agentBot = $this->agentBot();
        $this->telegram()->on('getMe', static fn(array $params, string $token): array => ['id' => 1, 'username' => $token === FakeTelegram::TOKEN ? 'amo_bot' : 'agent_shop_bot', 'first_name' => 'AmoBot']);

        $tester = $this->command(BotWebhookSetCommand::class, ['--drop-pending' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $set = $this->webhooksSet();
        $secret = (string) $this->service(ConfigFile::class)->get('TELEGRAM_WEBHOOK_SECRET');
        self::assertNotSame('', $secret, 'made and kept in config.php');
        self::assertSame([
            FakeTelegram::TOKEN => "https://shop.example/shop/webhooks/telegram/{$secret}",
            FakeTelegram::AGENT_TOKEN => "https://shop.example/shop/webhooks/telegram/bot/{$agentBot->id}/{$agentBot->refresh()->webhook_secret}",
        ], $set, 'each bot at its own address, the sub-folder APP_URL\'s alone');
        self::assertStringContainsString('Generated TELEGRAM_WEBHOOK_SECRET', $tester->getDisplay());
        self::assertSame($set[FakeTelegram::AGENT_TOKEN], CurrentBot::run($agentBot, fn() => $this->service(BotState::class)->webhookUrl()), 'the dashboard reads the mode');
    }

    public function testAnAgentsBotTelegramRefusesKeepsTheReasonAndTheOthersAreSet(): void
    {
        $this->config(['app.url' => 'https://shop.example', 'telegram.webhook_secret' => 'main-secret']);
        $agentBot = $this->agentBot();
        $this->telegram()->on('setWebhook', static fn(array $params, string $token): mixed => $token === FakeTelegram::AGENT_TOKEN ? FakeTelegram::error(401, 'Unauthorized') : true);

        $tester = $this->command(BotWebhookSetCommand::class, ['--url' => 'https://other.example']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('https://other.example/webhooks/telegram/main-secret', $tester->getDisplay(), 'the main bot set, under the --url given');
        self::assertStringContainsString("Agent bot #{$agentBot->id}: Unauthorized", $tester->getDisplay());
        self::assertSame(BotHealth::TOKEN_REFUSED, $agentBot->refresh()->problem);
    }

    public function testABotThatIsSetAgainLosesTheReasonItWasSilent(): void
    {
        $this->config(['app.url' => 'https://shop.example', 'telegram.webhook_secret' => 'main-secret']);
        $agentBot = $this->agentBot(overrides: ['problem' => BotHealth::TOKEN_REFUSED]);

        self::assertSame(Command::SUCCESS, $this->command(BotWebhookSetCommand::class)->getStatusCode());

        self::assertNull($agentBot->refresh()->problem, 'the agent\'s account no longer says their bot is down');
    }

    public function testTheMainBotFailingKeepsTheCommandFromNoneOfTheOthers(): void
    {
        $agentBot = $this->agentBot();
        $this->telegram()->on('deleteWebhook', static fn(array $params, string $token): mixed => $token === FakeTelegram::TOKEN ? FakeTelegram::error(401, 'Unauthorized') : true);
        $this->telegram()->on('getMe', static fn(array $params, string $token): mixed => $token === FakeTelegram::TOKEN ? ['id' => FakeTelegram::BOT_ID, 'username' => 'amo_bot', 'first_name' => 'AmoBot'] : FakeTelegram::error(401, 'Unauthorized'));

        $delete = $this->command(BotWebhookDeleteCommand::class);
        self::assertSame(Command::FAILURE, $delete->getStatusCode(), 'not every bot went through');
        self::assertStringContainsString('The main bot: Unauthorized', $delete->getDisplay());
        self::assertStringContainsString("Agent bot #{$agentBot->id}: webhook removed.", $delete->getDisplay(), 'the agent\'s was still taken down');

        $info = $this->command(BotInfoCommand::class);
        self::assertSame(Command::FAILURE, $info->getStatusCode());
        self::assertStringContainsString('@amo_bot', $info->getDisplay(), 'the main bot still described');
        self::assertStringContainsString("Agent bot #{$agentBot->id}: Unauthorized", $info->getDisplay());
    }

    public function testInfoWithoutAMainTokenHasNobodyToAskAbout(): void
    {
        $this->config(['telegram.token' => '']);

        $info = $this->command(BotInfoCommand::class);

        self::assertSame(Command::FAILURE, $info->getStatusCode());
        self::assertStringContainsString(BotLifecycle::NO_TOKEN, $info->getDisplay());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAPlainHttpAddressIsRefusedBeforeAnyBot(): void
    {
        $this->config(['app.url' => 'http://shop.example']);
        $this->agentBot();

        $tester = $this->command(BotWebhookSetCommand::class);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('APP_URL', $tester->getDisplay(), 'it says what to set right');
        self::assertSame([], $this->telegram()->calls());
        self::assertNull($this->service(ConfigFile::class)->get('TELEGRAM_WEBHOOK_SECRET'), 'no secret made for an address Telegram would not call');
    }

    public function testEveryBotsWebhookIsTakenDown(): void
    {
        $this->agentBot();

        $tester = $this->command(BotWebhookDeleteCommand::class, ['--drop-pending' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $deleted = array_keys(array_filter($this->telegram()->calls(), static fn(string $method): bool => $method === 'deleteWebhook'));
        self::assertSame([FakeTelegram::TOKEN, FakeTelegram::AGENT_TOKEN], array_map($this->telegram()->tokenOf(...), $deleted));
        self::assertSame('true', $this->telegram()->params($deleted[0])['drop_pending_updates']);
    }

    public function testInfoSaysWhoEveryBotIsAndHowItIsWired(): void
    {
        $this->agentBot();
        $this->telegram()->on('getMe', static fn(array $params, string $token): array => ['id' => 1, 'username' => $token === FakeTelegram::TOKEN ? 'amo_bot' : 'agent_shop_bot', 'first_name' => 'AmoBot']);
        $this->telegram()->on('getWebhookInfo', static fn(array $params, string $token): array => ['url' => $token === FakeTelegram::TOKEN ? 'https://shop.example/webhooks/telegram/x' : '', 'pending_update_count' => 3]);

        $display = $this->command(BotInfoCommand::class)->getDisplay();

        self::assertStringContainsString('@amo_bot', $display);
        self::assertStringContainsString('@agent_shop_bot', $display);
        self::assertStringContainsString('https://shop.example/webhooks/telegram/x', $display);
        self::assertStringContainsString('not set (polling mode)', $display);
    }

    /**
     * @param class-string<Command> $command
     * @param array<string, mixed> $input
     */
    private function command(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->service($command));
        $tester->execute($input);

        return $tester;
    }

    /** @return array<string, string> The webhook each bot registered, by its token */
    private function webhooksSet(): array
    {
        $set = [];
        foreach ($this->telegram()->calls() as $i => $method) {
            if ($method === 'setWebhook') {
                $set[$this->telegram()->tokenOf($i)] = $this->telegram()->params($i)['url'];
            }
        }

        return $set;
    }
}
