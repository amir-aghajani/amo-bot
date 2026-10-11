<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use App\Core\Config\ConfigFile;
use App\Core\Config\Repository as Config;
use App\Core\Http\Urls;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\BotState;
use App\Modules\Telegram\Exceptions\WebhookAddressException;
use Psr\Log\LoggerInterface;

/**
 * How the current bot is wired to Telegram: a webhook or polling (which needs the webhook gone), and the identity
 * Telegram reports. The main bot keeps its webhook secret and @username in config.php, an agent's bot on its row with a
 * webhook address of its own. An agent's bot handed over later follows the main bot's mode (follow()).
 */
final class BotLifecycle
{
    public const NO_TOKEN = 'No bot token configured (TELEGRAM_BOT_TOKEN in config.php — or set it from the admin settings screen).';

    /**
     * The calls Telegram makes to an agent's bot's webhook at once (setWebhook's max_connections; the main bot keeps
     * Telegram's 40): each holds one of the PHP workers every shop shares while it is served.
     */
    public const AGENT_CONNECTIONS = 4;

    public function __construct(
        private readonly BotApi $api,
        private readonly BotState $state,
        private readonly BotHealth $health,
        private readonly ConfigFile $file,
        private readonly Urls $urls,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The public address webhooks are registered under: `$baseUrl` (bot:webhook:set --url), else APP_URL.
     *
     * @throws WebhookAddressException when it is not HTTPS, which Telegram refuses
     */
    public function webhookBase(?string $baseUrl): string
    {
        $base = $this->urls->base($baseUrl);
        if (!str_starts_with($base, 'https://')) {
            throw new WebhookAddressException();
        }

        return $base;
    }

    /**
     * Register the current bot's webhook (an agent's held to AGENT_CONNECTIONS calls at once), making its secret first if
     * missing: the main bot's in config.php, an agent's on its row.
     *
     * @return string The URL registered
     * @throws WebhookAddressException when the URL is not HTTPS, which Telegram refuses
     * @throws TelegramApiException
     */
    public function enableWebhook(?string $baseUrl, bool $dropPending): string
    {
        $base = $this->webhookBase($baseUrl);
        $bot = CurrentBot::get();
        if ($bot->isMain()) {
            $secret = (string) $this->config->get('telegram.webhook_secret', '');
            if ($secret === '') {
                $secret = self::secret();
                $this->file->set('TELEGRAM_WEBHOOK_SECRET', $secret);
            }
            $url = $this->urls->telegramWebhook($secret, $base);
        } else {
            $secret = (string) $bot->webhook_secret;
            if ($secret === '') {
                $secret = self::secret();
                $bot->forceFill(['webhook_secret' => $secret])->save();
            }
            $url = $this->urls->agentWebhook($bot->id, $secret, $base);
        }

        $this->api->setWebhook($url, $secret, (array) $this->config->get('telegram.allowed_updates', []), $dropPending, $bot->isMain() ? null : self::AGENT_CONNECTIONS);
        $this->state->webhookSet($url);

        return $url;
    }

    /**
     * Take the current bot's webhook down (Telegram allows polling only without one).
     *
     * @throws TelegramApiException
     */
    public function disableWebhook(bool $dropPending): void
    {
        $this->api->deleteWebhook($dropPending);
        $this->state->webhookCleared();
    }

    /**
     * Ask Telegram who the current bot is and keep it (the main bot's @username in config.php, an agent's on its row);
     * returns the username.
     *
     * @throws TelegramApiException
     */
    public function rememberIdentity(): string
    {
        $me = $this->api->identity();

        $bot = CurrentBot::get();
        if ($bot->isMain()) {
            $this->rememberMainUsername($me['username']);
        } elseif ($bot->exists) {
            $bot->forceFill([
                'username' => $me['username'] !== '' ? $me['username'] : $bot->username,
                'title' => $me['name'] !== '' ? $me['name'] : $bot->title,
            ])->save();
        }

        return $me['username'];
    }

    /**
     * An agent's bot handed over (or given a new token) follows the main bot's mode: its own webhook when the shop runs
     * on webhooks, none otherwise (bot:poll takes it up). A refusal is kept on its row (BotHealth).
     */
    public function follow(Bot $bot): void
    {
        $webhooks = CurrentBot::run(Bot::MAIN, fn(): bool => $this->state->webhookUrl() !== null);

        try {
            CurrentBot::run($bot, function () use ($webhooks): void {
                $webhooks ? $this->enableWebhook(null, false) : $this->disableWebhook(false);
            });
        } catch (TelegramApiException | WebhookAddressException $e) {
            // A refusal that keeps the bot from running is BotHealth's to log and keep; anything else is a warning here.
            if (!($e instanceof TelegramApiException && $this->health->refused($bot, $e))) {
                $this->logger->warning('Bot #{bot} could not follow the shop\'s webhook mode: {message}', ['bot' => $bot->id, 'message' => $e->getMessage()]);
            }
        }
    }

    private function rememberMainUsername(string $username): void
    {
        if ($username === '' || $this->file->get('TELEGRAM_BOT_USERNAME') === $username) {
            return;
        }

        try {
            $this->file->set('TELEGRAM_BOT_USERNAME', $username);
        } catch (\RuntimeException $e) {
            $this->logger->warning('The bot\'s @username could not be written to config.php: {message}', ['message' => $e->getMessage()]);
        }
    }

    private static function secret(): string
    {
        return bin2hex(random_bytes(24));
    }
}
