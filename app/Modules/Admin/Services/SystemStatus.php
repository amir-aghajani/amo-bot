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
 * Whether the machinery behind the shop is alive — the owner's alone: an agent is not told about the installation.
 * The version the shop runs and PHP's; the bot of the shop the request is worked in, by its id (whether it has a token,
 * the admin's master switch, how it receives its updates and when the last one came); the main bot's the same way —
 * how every bot of the installation gets its updates is the main bot's way (BotWebhooks puts them all on webhooks or
 * takes them all off), and its token is the installation's —; and the scheduler (when it last ran, how many tasks it
 * has).
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
     * How the current bot runs.
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
