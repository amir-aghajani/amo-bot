<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

use App\Core\Security\RateLimiter;
use App\Core\Security\ThrottleUnavailableException;
use App\Modules\Bots\Models\Bot;
use App\Modules\Users\Models\User;
use Psr\Log\LoggerInterface;

/**
 * What an agent's bot may be sent through its webhook. Its agent reads the webhook's address — its secret with it — from
 * Telegram with their own token, so what arrives there may be theirs rather than Telegram's: it works in their own shop
 * only, but on what every shop shares — the PHP workers, the database, the panels, the mail server. So a bot takes UPDATES
 * updates in UPDATE_SECONDS, and NEWCOMERS updates from someone it has never seen in NEWCOMER_SECONDS (each would register
 * a customer — a row, a chat, a report); past either an update is acknowledged — Telegram, which sends anything else
 * again, lets it go — and dropped, which the log hears once a window. Telegram itself holds an agent's webhook to a few
 * calls at once (BotLifecycle::AGENT_CONNECTIONS). The main bot's webhook is Telegram's alone — its secret is the
 * owner's —, and so is what bot:poll fetches: neither is counted.
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
     * Whether the agent's bot — the shop being worked in (CurrentBot::run()) — serves the update: not past its window of
     * updates, nor — one from someone it has no customer for — of newcomers.
     *
     * @throws ThrottleUnavailableException
     */
    public function admits(Bot $bot, Update $update): bool
    {
        return $this->within($bot, 'updates', self::UPDATES, self::UPDATE_SECONDS)
            && (!self::fromNewcomer($update) || $this->within($bot, 'newcomers', self::NEWCOMERS, self::NEWCOMER_SECONDS));
    }

    /**
     * One more of `$what` counted in the bot's window: true while the window has room, and the log told once as it fills.
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
