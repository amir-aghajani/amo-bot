<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

use App\Core\Security\RateLimiter;
use App\Core\Security\ThrottleUnavailableException;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;
use Psr\Log\LoggerInterface;

/**
 * Rate limits on an agent's bot's webhook. The agent can read the webhook's secret from Telegram with their own token,
 * so they could post updates themselves and load what every shop shares (workers, database, panels). A bot takes
 * UPDATES updates a minute and NEWCOMERS updates an hour from people it has never seen (each registers a customer);
 * beyond that, updates are acknowledged and dropped. The main bot's webhook and polled updates are not counted.
 */
final class AgentWebhookBudget
{
    /** Updates a bot takes in UPDATE_SECONDS: more than its customers send, fewer than would hold the shop up. */
    public const UPDATES = 600;

    public const UPDATE_SECONDS = 60;

    /** Updates from someone the bot has never seen it takes in NEWCOMER_SECONDS: a busy day's newcomers in an hour. */
    public const NEWCOMERS = 300;

    public const NEWCOMER_SECONDS = 3600;

    public function __construct(
        private readonly RateLimiter $counts,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whether the agent's bot (the current shop) serves the update: within its window of updates and, for someone it
     * has no customer for, of newcomers.
     *
     * @throws ThrottleUnavailableException
     */
    public function admits(Bot $bot, Update $update): bool
    {
        return $this->within($bot, 'updates', self::UPDATES, self::UPDATE_SECONDS)
            && (!self::fromNewcomer($update) || $this->within($bot, 'newcomers', self::NEWCOMERS, self::NEWCOMER_SECONDS));
    }

    /**
     * Count one more `$what` in the bot's window: true while it has room; a warning is logged once as it fills.
     *
     * @throws ThrottleUnavailableException
     */
    private function within(Bot $bot, string $what, int $limit, int $seconds): bool
    {
        $count = $this->counts->hit("agent-webhook|{$what}|{$bot->id}", $seconds);
        if ($count === $limit + 1) {
            $this->logger->warning('Agent bot #{bot} was sent over {limit} {what} in {seconds} seconds through its webhook: the rest of them in that time are acknowledged and dropped', [
                'bot' => $bot->id,
                'limit' => $limit,
                'what' => $what,
                'seconds' => $seconds,
            ]);
        }

        return $count <= $limit;
    }

    /** Whether the update comes from a private chat whose person the current bot has no customer for: serving it registers one. */
    private static function fromNewcomer(Update $update): bool
    {
        $from = $update->fromId();

        return $update->isPrivateChat() && $from !== null && !User::query()->where('telegram_id', $from)->exists();
    }
}
