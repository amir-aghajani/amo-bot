<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Update\ReceivedUpdates;
use Illuminate\Support\Carbon;

/**
 * What the current bot is up to, as runtime state (not configuration — that is config.php): the webhook it registered, when
 * the poller last came round (the poller writes it every few rounds), when the last update arrived (the newest one it
 * took, ReceivedUpdates). The commands write it; the dashboard reads it to say "webhook", "polling" or "silent".
 */
final class BotState
{
    private const WEBHOOK_URL = 'telegram.webhook_url';
    private const POLL_HEARTBEAT_AT = 'telegram.poll_heartbeat_at';

    public function __construct(
        private readonly Settings $settings,
        private readonly ReceivedUpdates $updates,
    ) {}

    public function webhookSet(string $url): void
    {
        $this->settings->set(self::WEBHOOK_URL, $url);
    }

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

    public function heartbeatAt(): ?Carbon
    {
        $value = $this->settings->get(self::POLL_HEARTBEAT_AT);

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    public function lastUpdateAt(): ?Carbon
    {
        return $this->updates->lastAt();
    }
}
