<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

use App\Modules\Bots\CurrentBot;
use App\Modules\Telegram\Api\BotApi;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Each bot's served updates by `update_id` (`telegram_updates`), recorded before serving so a copy Telegram sends again
 * is never served twice — at most once, by design: an update whose serving died halfway is not retried either. Records
 * live KEEP_HOURS (Telegram keeps an update a day). Each also names its chat, which gives a chat's pace (lately()): more
 * than FLOOD_UPDATES in FLOOD_SECONDS is a flood. An update Telegram held back while the bot was away counts against no
 * chat, and neither does a group's talk the bot is only sent (Update::isForBot()): a busy report group must not hold
 * back an admin's press or reply.
 */
final class ReceivedUpdates
{
    public const KEEP_HOURS = 48;

    /** The most updates of one chat served within FLOOD_SECONDS: an album of photos, a burst of taps, with room to spare. */
    public const FLOOD_UPDATES = 20;

    public const FLOOD_SECONDS = 10;

    /** An update whose message is older than this as it arrives was held back by Telegram: no part of the chat's pace. */
    private const BACKLOG_SECONDS = 60;

    private const TABLE = 'telegram_updates';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly BotApi $api,
    ) {}

    /** True the first time the current bot gets this update, false for a copy of one it took already. */
    public function claim(Update $update): bool
    {
        return $this->db->table(self::TABLE)->insertOrIgnore([
            'bot_id' => CurrentBot::id(),
            'update_id' => $update->id(),
            'chat_id' => $this->paced($update) ? $update->chatId() : null,
            'received_at' => now(),
        ]) === 1;
    }

    /**
     * How many updates of the chat `$update` counts against came within FLOOD_SECONDS, this one among them (claim() it
     * first); 0 for one that counts against none.
     */
    public function lately(Update $update): int
    {
        if (!$this->paced($update)) {
            return 0;
        }

        return $this->db->table(self::TABLE)
            ->where('bot_id', CurrentBot::id())
            ->where('chat_id', $update->chatId())
            ->where('received_at', '>=', now()->subSeconds(self::FLOOD_SECONDS))
            ->count();
    }

    /** When the current bot last got an update, by webhook or poll alike; null before its first. */
    public function lastAt(): ?Carbon
    {
        $at = $this->db->table(self::TABLE)->where('bot_id', CurrentBot::id())->max('received_at');

        return is_string($at) ? Carbon::parse($at) : null;
    }

    /** Forget the updates Telegram can no longer send again, every bot's. */
    public function prune(): void
    {
        $this->db->table(self::TABLE)->where('received_at', '<', now()->subHours(self::KEEP_HOURS))->delete();
    }

    /**
     * Whether the update counts against its chat's pace: its chat sent it just now (a press carries no date: it did) —
     * and, in a group, sent it to the bot.
     */
    private function paced(Update $update): bool
    {
        $sent = $update->sentAt();

        return $update->chatId() !== null
            && ($sent === null || $sent >= now()->getTimestamp() - self::BACKLOG_SECONDS)
            && (!$update->isGroupChat() || $update->isForBot($this->api->botId()));
    }
}
