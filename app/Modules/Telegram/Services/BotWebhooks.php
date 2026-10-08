<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use App\Core\Logging\Redact;
use App\Modules\Bots\BotOutcome;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\Api\BotToken;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Exceptions\WebhookAddressException;
use Psr\Log\LoggerInterface;

/**
 * How the shop's bots get their updates, all of them at once: on webhooks — each at its address under APP_URL, the main
 * bot first, so an agent's bot handed over later follows the mode it is in (BotLifecycle::follow()) — or off them, back
 * to polling (bot:poll takes the updates). The work of bot:webhook:set and bot:webhook:delete, and of the owner's panel
 * for a host without a shell (POST|DELETE /api/admin/system/webhook): one bot that fails keeps it from none of the
 * others, an agent's bot Telegram refuses gets the reason on its row (BotHealth), and each bot's outcome is told —
 * present() words it for the owner, never with a token nor the secret a webhook's address ends with.
 */
final class BotWebhooks
{
    /** What the owner reads of a bot put on its webhook. */
    public const ENABLED = 'Webhook ثبت شد؛ تلگرام پیام‌ها را مستقیم به فروشگاه می‌فرستد.';

    /** What the owner reads of a bot taken off its webhook. */
    public const DISABLED = 'Webhook برداشته شد؛ پیام‌ها فقط وقتی می‌رسند که bot:poll روی سرور اجرا باشد.';

    /** A failure of the shop's own (its config.php, the database): the log has it. */
    private const FAILED = 'انجام نشد؛ جزئیات در لاگ برنامه است.';

    public function __construct(
        private readonly Bots $bots,
        private readonly BotLifecycle $lifecycle,
        private readonly BotHealth $health,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Every bot that runs on its webhook, at `$baseUrl` in APP_URL's place when given (bot:webhook:set --url), its
     * identity remembered.
     *
     * @return list<BotOutcome<string>> Each bot's: the address registered, or what stopped it
     * @throws WebhookAddressException when the address is not HTTPS — no bot is touched then
     */
    public function enable(?string $baseUrl = null, bool $dropPending = false): array
    {
        $this->lifecycle->webhookBase($baseUrl);

        return $this->bots->eachServing(function (Bot $bot) use ($baseUrl, $dropPending): string {
            try {
                $url = $this->lifecycle->enableWebhook($baseUrl, $dropPending);
                $this->lifecycle->rememberIdentity();
            } catch (TelegramApiException $e) {
                $this->health->refused($bot, $e);

                throw $e;
            }
            $this->health->running($bot);

            return $url;
        });
    }

    /**
     * Every bot that runs off its webhook — polling and webhooks are exclusive on Telegram's side —, the updates queued
     * meanwhile discarded when `$dropPending`.
     *
     * @return list<BotOutcome<null>>
     */
    public function disable(bool $dropPending = false): array
    {
        return $this->bots->eachServing(function () use ($dropPending): null {
            $this->lifecycle->disableWebhook($dropPending);

            return null;
        });
    }

    /**
     * Each bot's outcome as the owner reads it: which bot, and `$done` (ENABLED, DISABLED) — or why it did not go
     * through, in the words a refusal of Telegram's is told in elsewhere.
     *
     * @param list<BotOutcome<mixed>> $outcomes
     * @return list<array{bot: array{id: int, username: string|null}, done: bool, message: string}>
     */
    public function present(array $outcomes, string $done): array
    {
        return array_map(fn(BotOutcome $outcome): array => [
            'bot' => ['id' => $outcome->bot->id, 'username' => $this->bots->username($outcome->bot) ?: null],
            'done' => $outcome->failure === null,
            'message' => $outcome->failure === null ? $done : $this->explain($outcome->bot, $outcome->failure),
        ], $outcomes);
    }

    private function explain(Bot $bot, \Throwable $failure): string
    {
        if (!$failure instanceof TelegramApiException) {
            $this->logger->error('Bot #{bot}\'s webhook could not be changed: {message}', ['bot' => $bot->id, 'message' => $failure->getMessage(), 'exception' => $failure]);

            return self::FAILED;
        }

        return match ($failure->refusal()) {
            // The main bot's token is the owner's to replace; an agent's, the agent's (the reason kept on its row).
            Refusal::TokenRejected => $bot->isMain() ? BotToken::REFUSED : BotHealth::TOKEN_REFUSED,
            Refusal::Conflict => BotHealth::TOKEN_IN_USE,
            Refusal::Unreachable, Refusal::FloodWait => BotToken::UNREACHABLE,
            default => 'تلگرام نپذیرفت: ' . Redact::text($failure->getMessage()),
        };
    }
}
