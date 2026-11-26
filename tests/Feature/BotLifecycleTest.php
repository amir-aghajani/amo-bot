<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config\ConfigFile;
use App\Modules\Backups\BackupScope;
use App\Modules\Backups\BackupWriter;
use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Exceptions\WebhookAddressException;
use App\Modules\Telegram\Services\BotHealth;
use App\Modules\Telegram\Services\BotLifecycle;
use App\Modules\Telegram\Services\BotWebhooks;
use Tests\DatabaseTestCase;
use Tests\Support\FakeTelegram;

/**
 * Wiring a bot to Telegram: the webhook at APP_URL (the shop's address, its sub-folder included), the secret made when
 * missing, the state the dashboard reads (the address without its secret), and polling taking the webhook down — an
 * agent's bot at an address of its own, with a secret of its own, following the main bot's mode, and a refusal that
 * keeps it from running told on its row. The main bot's @username lands in config.php when it changed; a config.php
 * that cannot be written costs only that.
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
        self::assertSame('https://shop.example/panel/webhooks/telegram/***', $this->state()->webhookUrl(), 'remembered without its secret');
        self::assertStringNotContainsString('s3cret', (string) $this->db()->table('settings')->where('key', 'telegram.webhook_url')->value('value'), 'never in the settings a backup holds');
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

    /**
     * A whole installation's backup — which an admin of the main bot downloads, or has sent to a report group — holds no
     * webhook's secret: neither the main bot's nor an agent's, each address kept with its secret masked.
     */
    public function testABackupHoldsNoWebhooksSecret(): void
    {
        $bot = $this->agentBot();
        $this->lifecycle()->enableWebhook(null, dropPending: false);
        $this->lifecycle()->follow($bot);
        $secret = (string) $bot->refresh()->webhook_secret;
        self::assertSame(48, strlen($secret), 'the agent\'s bot is on its webhook');

        $path = $this->scratchDir() . '/installation.json.gz';
        $this->service(BackupWriter::class)->write(BackupScope::installation(), $path);
        $file = implode('', gzfile($path) ?: []);

        self::assertStringContainsString('https://shop.example/panel/webhooks/telegram/***', $file, 'the main bot\'s address is there, masked');
        self::assertStringContainsString("https://shop.example/panel/webhooks/telegram/bot/{$bot->id}/***", $file, 'and the agent\'s');
        self::assertStringNotContainsString('s3cret', $file, 'never the main bot\'s secret');
        self::assertStringNotContainsString($secret, $file, 'nor the agent\'s');
    }

    /**
     * «برداشتن Webhook» then «ثبت Webhook» (BotWebhooks, the panel's and the bot's «دریافت پیام‌ها») renew every agent's
     * bot's secret — one an old backup carried, say —: forgotten once Telegram took its webhook down, a new one made and
     * registered with Telegram in the same step as its webhook. The main bot's stays config.php's.
     */
    public function testTakingTheWebhooksDownAndPuttingThemBackGivesEveryAgentsBotANewSecret(): void
    {
        $bot = $this->agentBot();
        $this->telegram()->on('getMe', static fn(array $params, string $token): array => ['id' => BotToken::botId($token), 'username' => $token === FakeTelegram::TOKEN ? 'amo_shop_bot' : 'agent_shop_bot', 'first_name' => 'AmoBot']);
        $webhooks = $this->service(BotWebhooks::class);
        $webhooks->enable();
        $old = (string) $bot->refresh()->webhook_secret;
        self::assertSame(48, strlen($old));

        $webhooks->disable();
        self::assertNull($bot->refresh()->webhook_secret, 'forgotten once Telegram took the webhook down');
        self::assertNull($this->db()->table('bots')->where('id', $bot->id)->value('webhook_secret'));

        $this->telegram()->reset();
        $webhooks->enable();

        $new = (string) $bot->refresh()->webhook_secret;
        self::assertSame(48, strlen($new));
        self::assertNotSame($old, $new, 'a new secret');
        $set = $this->webhooksSet();
        self::assertSame(["https://shop.example/panel/webhooks/telegram/bot/{$bot->id}/{$new}", $new], $set[FakeTelegram::AGENT_TOKEN] ?? null, 'registered with Telegram in the same step');
        self::assertSame(['https://shop.example/panel/webhooks/telegram/s3cret', 's3cret'], $set[FakeTelegram::TOKEN] ?? null, 'the main bot\'s secret untouched');
        self::assertNull($this->file->get('TELEGRAM_WEBHOOK_SECRET'), 'config.php not rewritten');
    }

    /**
     * An agent's bot that does not run now (its agency taken away) forgets its secret too when the webhooks are taken down
     * — nothing of it is asked of Telegram —, and gets a new one when its webhook is registered again.
     */
    public function testAnAgentsBotThatIsOffGetsANewSecretToo(): void
    {
        $bot = $this->agentBot();
        $this->telegram()->on('getMe', static fn(array $params, string $token): array => ['id' => BotToken::botId($token), 'username' => $token === FakeTelegram::TOKEN ? 'amo_shop_bot' : 'agent_shop_bot', 'first_name' => 'AmoBot']);
        $webhooks = $this->service(BotWebhooks::class);
        $webhooks->enable();
        $old = (string) $bot->refresh()->webhook_secret;
        self::assertSame(48, strlen($old));
        $level = $this->db()->table('users')->where('id', $bot->user_id)->value('agency_level_id');
        $this->db()->table('users')->where('id', $bot->user_id)->update(['agency_level_id' => null]);

        $this->telegram()->reset();
        $webhooks->disable();
        self::assertSame([FakeTelegram::TOKEN], array_map($this->telegram()->tokenOf(...), array_keys($this->telegram()->calls())), 'only the bots that run are asked');
        self::assertNull($bot->refresh()->webhook_secret, 'forgotten all the same');

        $this->db()->table('users')->where('id', $bot->user_id)->update(['agency_level_id' => $level]);
        $this->telegram()->reset();
        $webhooks->enable();
        $new = (string) $bot->refresh()->webhook_secret;
        self::assertNotSame($old, $new, 'a new secret');
        self::assertSame(["https://shop.example/panel/webhooks/telegram/bot/{$bot->id}/{$new}", $new], $this->webhooksSet()[FakeTelegram::AGENT_TOKEN] ?? null);
    }

    /**
     * Telegram refusing to take an agent's bot's webhook down forgets nothing — the webhook may still be Telegram's —, and
     * «ثبت Webhook» registers it again with the secret it has.
     */
    public function testAWebhookTelegramWouldNotTakeDownKeepsItsSecretAndIsRegisteredAgain(): void
    {
        $bot = $this->agentBot();
        $this->lifecycle()->enableWebhook(null, dropPending: false);
        $this->lifecycle()->follow($bot);
        $secret = (string) $bot->refresh()->webhook_secret;

        $this->telegram()->reset();
        $this->telegram()->fail(400, 'Bad Request: try again later');
        try {
            CurrentBot::run($bot, fn() => $this->lifecycle()->disableWebhook(false));
            self::fail('Telegram\'s refusal was swallowed');
        } catch (TelegramApiException) {
        }
        self::assertSame($secret, $bot->refresh()->webhook_secret, 'kept');
        self::assertNotNull(CurrentBot::run($bot, fn(): ?string => $this->state()->webhookUrl()), 'still on its webhook, as far as the shop knows');

        $this->telegram()->reset();
        CurrentBot::run($bot, fn(): string => $this->lifecycle()->enableWebhook(null, dropPending: false));
        self::assertSame(["https://shop.example/panel/webhooks/telegram/bot/{$bot->id}/{$secret}", $secret], [$this->telegram()->params(0)['url'], $this->telegram()->params(0)['secret_token']]);
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
        self::assertSame("https://shop.example/panel/webhooks/telegram/bot/{$bot->id}/***", CurrentBot::run($bot, fn(): ?string => $this->state()->webhookUrl()), 'its own state, without its secret');
        self::assertStringNotContainsString($secret, (string) $this->db()->table('settings')->where('bot_id', $bot->id)->where('key', 'telegram.webhook_url')->value('value'), 'never in its shop\'s settings, which its backup holds');
        self::assertSame('https://shop.example/panel/webhooks/telegram/***', $this->state()->webhookUrl(), "the main bot's untouched");

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

    /**
     * Each bot's webhook registered since the last reset, by the token it was asked with: [its address, its secret].
     *
     * @return array<string, array{string, string}>
     */
    private function webhooksSet(): array
    {
        $set = [];
        foreach ($this->telegram()->calls() as $i => $method) {
            if ($method === 'setWebhook') {
                $set[$this->telegram()->tokenOf($i)] = [(string) $this->telegram()->params($i)['url'], (string) $this->telegram()->params($i)['secret_token']];
            }
        }

        return $set;
    }
}
