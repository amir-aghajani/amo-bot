<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Application;
use App\Core\Scheduling\Scheduler;
use App\Modules\Bots\CurrentBot;
use App\Modules\Bots\Models\Bot;
use App\Modules\Bots\Services\Bots;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\BotState;

/**
 * Whether the machinery behind the shop is alive (the owner's dashboard card; agents are not told about the installation):
 * the versions, the open shop's bot and the main bot (token, master switch, how updates arrive, the last one), and the
 * scheduler. The main bot is shown too because its mode is every bot's (BotWebhooks switches them all together).
 */
final class SystemStatus
{
    /** A poller that has not written its heartbeat for this long is taken as gone. */
    private const HEARTBEAT_MINUTES = 2;

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly BotSettings $botSettings,
        private readonly BotState $botState,
        private readonly Bots $bots,
    ) {}

    /** @return array<string, mixed> */
    public function present(): array
    {
        $shop = CurrentBot::get();

        return [
            'version' => Application::VERSION,
            'php' => PHP_VERSION,
            'bot' => ['id' => $shop->id] + $this->bot(),
            'main_bot' => CurrentBot::run(Bot::MAIN, fn(): array => $this->bot()),
            'cron' => [
                'last_run_at' => $this->scheduler->lastRunAt()?->format(DATE_ATOM),
                'tasks' => count($this->scheduler->tasks()),
            ],
        ];
    }

    /**
     * How the current bot runs: `mode` is webhook, polling (a heartbeat within HEARTBEAT_MINUTES) or offline.
     *
     * @return array{configured: bool, enabled: bool, username: string|null, mode: string, last_update_at: string|null}
     */
    private function bot(): array
    {
        $bot = CurrentBot::get();
        $polling = $this->botState->heartbeatAt()?->gt(now()->subMinutes(self::HEARTBEAT_MINUTES)) ?? false;

        return [
            'configured' => $this->bots->hasToken($bot),
            'enabled' => $this->botSettings->enabled(),
            'username' => $this->bots->username($bot) ?: null,
            'mode' => $this->botState->webhookUrl() !== null ? 'webhook' : ($polling ? 'polling' : 'offline'),
            'last_update_at' => $this->botState->lastUpdateAt()?->toIso8601String(),
        ];
    }
}
