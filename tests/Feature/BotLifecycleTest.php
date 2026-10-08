<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Exceptions\WebhookAddressException;
use App\Modules\Telegram\Services\BotHealth;
use App\Modules\Telegram\Services\BotLifecycle;
use Tests\DatabaseTestCase;
use Tests\Support\FakeTelegram;

/**
 * Wiring a bot to Telegram: the webhook at APP_URL (the shop's address, its sub-folder included), the secret made when
 * missing, the state the dashboard reads, and polling taking the webhook down — an agent's bot at an address of its
 * own, with a secret of its own, following the main bot's mode, and a refusal that keeps it from running told on its
 * row. The main bot's @username lands in config.php when it changed; a config.php that cannot be written costs only
 * that.
 */
final class BotLifecycleTest extends DatabaseTestCase
{
    private ConfigFile $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->file = $this->configFile(['APP_URL' => 'https://shop.example', 'TELEGRAM_BOT_TOKEN' => FakeTelegram::TOKEN]);
        $this->config(['app.url' => 'https://shop.example/panel', 'telegram.webhook_secret' => 's3cret', 'telegram.allowed_updates' => ['message']]);
    }

    public function testEnablingTheWebhookRegistersItsUrlAndRemembersIt(): void
    {
        $url = $this->lifecycle()->enableWebhook(null, dropPending: true);

        self::assertSame('https://shop.example/panel/webhooks/telegram/s3cret', $url, 'APP_URL is the shop\'s address, sub-folder and all');
        self::assertSame(['setWebhook'], $this->telegram()->calls());
        $params = $this->telegram()->params(0);
        self::assertSame($url, $params['url']);
        self::assertSame('s3cret', $params['secret_token']);
        self::assertSame('["message"]', $params['allowed_updates']);
        self::assertSame('true', $params['drop_pending_updates']);
        self::assertArrayNotHasKey('max_connections', $params, "the main bot's is Telegram's own");
        self::assertSame($url, $this->state()->webhookUrl());
        self::assertNull($this->file->get('TELEGRAM_WEBHOOK_SECRET'), 'a configured secret is not rewritten');
    }

    public function testAMissingSecretIsMadeAndWrittenToTheConfigFile(): void
    {
        $this->config(['telegram.webhook_secret' => '']);

        $url = $this->lifecycle()->enableWebhook(null, dropPending: false);

        $secret = $this->file->get('TELEGRAM_WEBHOOK_SECRET');
        self::assertNotNull($secret);
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $secret);
        self::assertSame("https://shop.example/panel/webhooks/telegram/{$secret}", $url);
        self::assertSame($secret, $this->telegram()->params(0)['secret_token']);
    }

    public function testAnOverrideBaseUrlIsUsedAndAPlainHttpOneIsRefusedBeforeTelegramIsAsked(): void
    {
        $this->config(['app.url' => 'http://localhost']);

        self::assertSame('https://tunnel.example/webhooks/telegram/s3cret', $this->lifecycle()->enableWebhook('https://tunnel.example/', dropPending: false));

        try {
            $this->lifecycle()->enableWebhook(null, dropPending: false);
            self::fail('expected the HTTP URL to be refused');
        } catch (WebhookAddressException $e) {
            self::assertStringContainsString('APP_URL', $e->getMessage(), 'it says what to set right');
        }
        self::assertSame(['setWebhook'], $this->telegram()->calls(), 'only the HTTPS one reached Telegram');
    }

    public function testDisablingTheWebhookClearsTheStateAndTheIdentityLandsInTheConfigFile(): void
    {
        $this->lifecycle()->enableWebhook(null, dropPending: false);
        $this->telegram()->reset();
        $this->telegram()->reply(true, ['id' => FakeTelegram::BOT_ID, 'username' => 'amo_shop_bot', 'first_name' => 'AmoBot']);

        $this->lifecycle()->disableWebhook(dropPending: true);
        self::assertSame('amo_shop_bot', $this->lifecycle()->rememberIdentity());

        self::assertSame(['deleteWebhook', 'getMe'], $this->telegram()->calls());
        self::assertSame('true', $this->telegram()->params(0)['drop_pending_updates']);
        self::assertNull($this->state()->webhookUrl());
        self::assertSame('amo_shop_bot', $this->file->get('TELEGRAM_BOT_USERNAME'));
    }

    public function testAnAgentsBotHasAWebhookOfItsOwnAndFollowsTheMainBotsMode(): void
    {
        $bot = $this->agentBot();

        // The shop polls: an agent's bot handed over is taken off any webhook it had.
        $this->lifecycle()->follow($bot);
        self::assertSame(['deleteWebhook'], $this->telegram()->calls());
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), 'asked as the agent\'s bot');

        // The shop runs on webhooks: the agent's bot gets its own address and secret.
        $this->lifecycle()->enableWebhook(null, dropPending: false);
        $this->telegram()->reset();
        $this->lifecycle()->follow($bot);

        self::assertSame(['setWebhook'], $this->telegram()->calls());
        $secret = (string) $bot->refresh()->webhook_secret;
        self::assertSame(48, strlen($secret));
        self::assertStringNotContainsString($secret, (string) $this->db()->table('bots')->where('id', $bot->id)->value('webhook_secret'), 'kept encrypted: a copy of the database forges no update');
        $url = "https://shop.example/panel/webhooks/telegram/bot/{$bot->id}/{$secret}";
        self::assertSame([$url, $secret], [$this->telegram()->params(0)['url'], $this->telegram()->params(0)['secret_token']]);
        self::assertSame((string) BotLifecycle::AGENT_CONNECTIONS, $this->telegram()->params(0)['max_connections'] ?? null, 'Telegram calls it a few at a time');
        self::assertSame($url, CurrentBot::run($bot, fn(): ?string => $this->state()->webhookUrl()), 'its own state');
        self::assertSame('https://shop.example/panel/webhooks/telegram/s3cret', $this->state()->webhookUrl(), "the main bot's untouched");

        // Its identity lands on its row.
        $this->telegram()->reply(['id' => BotToken::botId(FakeTelegram::AGENT_TOKEN), 'username' => 'agent_new_name', 'first_name' => 'فروشگاه نماینده']);
        CurrentBot::run($bot, fn(): string => $this->lifecycle()->rememberIdentity());
        self::assertSame(['agent_new_name', 'فروشگاه نماینده'], [$bot->refresh()->username, $bot->title]);
    }

    public function testAnAgentsBotTelegramRefusesIsToldOnItsRowAndAnythingElseIsOnlyLogged(): void
    {
        $bot = $this->agentBot();
        $logs = $this->logs();

        $this->telegram()->fail(401, 'Unauthorized');
        $this->lifecycle()->follow($bot);
        self::assertSame(BotHealth::TOKEN_REFUSED, $bot->refresh()->problem, 'its agent and the owner read why it does not run');
        self::assertFalse($logs->hasWarningThatContains('could not follow'), "the bot's health said it");

        $this->telegram()->fail(502, 'Bad Gateway');
        $this->lifecycle()->follow($bot);
        self::assertTrue($logs->hasWarningThatContains('could not follow'));
        self::assertSame(BotHealth::TOKEN_REFUSED, $bot->refresh()->problem, 'Telegram out of reach says nothing new about the bot');
    }

    public function testTheMainBotsUsernameIsWrittenToTheConfigFileOnlyWhenItChanged(): void
    {
        $path = $this->file->path();
        $this->telegram()->on('getMe', static fn(): array => ['id' => FakeTelegram::BOT_ID, 'username' => 'amo_shop_bot', 'first_name' => 'AmoBot']);

        self::assertSame('amo_shop_bot', $this->lifecycle()->rememberIdentity());
        self::assertSame('amo_shop_bot', $this->file->get('TELEGRAM_BOT_USERNAME'));

        touch($path, $before = time() - 3600);
        $this->lifecycle()->rememberIdentity();
        clearstatcache(true, $path);
        self::assertSame($before, filemtime($path), 'the same name again: the file is not rewritten');
    }

    public function testAConfigFileThatCannotBeWrittenCostsOnlyTheName(): void
    {
        // Something squats the file's name: the name cannot be written.
        $path = $this->file->path();
        unlink($path);
        mkdir($path);
        $logs = $this->logs();
        $this->telegram()->on('getMe', static fn(): array => ['id' => FakeTelegram::BOT_ID, 'username' => 'amo_shop_bot', 'first_name' => 'AmoBot']);

        self::assertSame('amo_shop_bot', $this->lifecycle()->rememberIdentity(), 'the bot goes on');
        self::assertTrue($logs->hasWarningThatContains('could not be written to config.php'));
    }

    private function lifecycle(): BotLifecycle
    {
        return $this->service(BotLifecycle::class);
    }

    private function state(): BotState
    {
        return $this->service(BotState::class);
    }
}
