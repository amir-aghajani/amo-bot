<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Services;

use App\Modules\Bots\Models\Bot;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use Psr\Log\LoggerInterface;

/**
 * What keeps an agent's bot from running, on its row (`bots.problem`) for the agent — their account in the main bot —
 * and the owner — the agents page — to read: written by whoever hears it from Telegram, the poller or the webhook's
 * registration, and cleared once the bot runs again. The main bot's own trouble is the operator's: the console and the
 * log say it.
 */
final class BotHealth
{
    /** Telegram refuses the token (revoked in @BotFather): the agent hands over a new one from the main bot. */
    public const TOKEN_REFUSED = 'تلگرام توکن این ربات را نمی‌پذیرد؛ توکن تازه را از @BotFather بگیرید و از ربات اصلی، بخش «ربات من»، بفرستید.';

    /** Another program takes the bot's updates: a second poller, or a webhook set for it elsewhere. */
    public const TOKEN_IN_USE = 'این ربات جای دیگری هم اجرا می‌شود (برنامه دیگری با همین توکن به‌روزرسانی‌ها را می‌گیرد)؛ آن را متوقف کنید.';

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Telegram said no to one of the bot's calls. When it means the bot cannot run — its token refused, its updates
     * taken elsewhere — it is logged and, for an agent's bot, kept on its row; true then. Anything else (Telegram out of
     * reach, a flood limit) is the caller's to wait out: false.
     */
    public function refused(Bot $bot, TelegramApiException $e): bool
    {
        $problem = match ($e->refusal()) {
            Refusal::TokenRejected => self::TOKEN_REFUSED,
            Refusal::Conflict => self::TOKEN_IN_USE,
            default => null,
        };
        if ($problem === null) {
            return false;
        }

        $this->logger->warning('Bot #{bot} cannot run: {message}', ['bot' => $bot->id, 'message' => $e->getMessage()]);
        if (!$bot->isMain() && $bot->problem !== $problem) {
            $bot->forceFill(['problem' => $problem])->save();
        }

        return true;
    }

    /** The bot runs: what kept it from running is over. */
    public function running(Bot $bot): void
    {
        if (!$bot->isMain() && $bot->problem !== null) {
            $bot->forceFill(['problem' => null])->save();
        }
    }
}
