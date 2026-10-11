<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Update\ReceivedUpdates;
use Illuminate\Support\Carbon;

/**
 * The current bot's runtime state, which the dashboard reads to say "webhook", "polling" or "offline": the webhook it
 * registered, the poller's heartbeat, and when the last update arrived.
 */
final class BotState
{
    private const WEBHOOK_URL = 'telegram.webhook_url';
    private const POLL_HEARTBEAT_AT = 'telegram.poll_heartbeat_at';

    public function __construct(
        private readonly Settings $settings,
        private readonly ReceivedUpdates $updates,
    ) {}

    /** The bot was put on its webhook at `$url`. */
    public function webhookSet(string $url): void
    {
        $this->settings->set(self::WEBHOOK_URL, $url);
    }

    /** The bot was taken off its webhook. */
    public function webhookCleared(): void
    {
        $this->settings->forget(self::WEBHOOK_URL);
    }

    /** The poller is alive. */
    public function heartbeat(): void
    {
        $this->settings->set(self::POLL_HEARTBEAT_AT, now()->toIso8601String());
    }

    /** The webhook URL registered by bot:webhook:set; null in polling mode. */
    public function webhookUrl(): ?string
    {
        $url = $this->settings->get(self::WEBHOOK_URL);

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** When the poller last said it is alive; null when it never did. */
    public function heartbeatAt(): ?Carbon
    {
        $value = $this->settings->get(self::POLL_HEARTBEAT_AT);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /** When the bot last got an update, by webhook or poll. */
    public function lastUpdateAt(): ?Carbon
    {
        return $this->updates->lastAt();
    }
}
