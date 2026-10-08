<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Update;

use App\Modules\Bots\CurrentBot;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * The updates each bot took, by Telegram's `update_id` (`telegram_updates`), recorded before one is served: a copy
 * Telegram sends again — a webhook answered past its patience, a poller that stopped before it confirmed its offset —
 * finds the record and is not served twice (no second order, receipt or link change). At most once, by decision: an
 * update whose serving died halfway is not served again either. Telegram holds an update a day at most, so a record
 * older than KEEP_HOURS is of no more use (prune()); a bot's newest says when it last heard from Telegram (lastAt()).
 *
 * Each record says the chat it counts against, so a chat's pace is read off the records (lately()): a person taps and
 * types far slower than FLOOD_UPDATES in FLOOD_SECONDS, and a chat past it is flooding the bot — every answer is a
 * Telegram call its limits slow down, for everyone the bot serves. Only what a chat sent just now counts: an update
 * Telegram held back while the bot was away — its message sent more than BACKLOG_SECONDS ago — is a chat's earlier
 * pace, not its present one, and counts against none.
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

    public function __construct(private readonly ConnectionInterface $db) {}

    /** True the first time the current bot gets this update, false for a copy of one it took already. */
    public function claim(Update $update): bool
    {
        return $this->db->table(self::TABLE)->insertOrIgnore([
            'bot_id' => CurrentBot::id(),
            'update_id' => $update->id(),
            'chat_id' => self::paced($update) ? $update->chatId() : null,
            'received_at' => now(),
        ]) === 1;
    }

    /**
     * How many updates of the chat `$update` counts against came within FLOOD_SECONDS, this one among them (claim() it
     * first); 0 for one that counts against none.
     */
    public function lately(Update $update): int
    {
        if (!self::paced($update)) {
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

    /** Whether the update counts against its chat's pace: its chat sent it just now (a press carries no date: it did). */
    private static function paced(Update $update): bool
    {
        $sent = $update->sentAt();

        return $update->chatId() !== null && ($sent === null || $sent >= now()->getTimestamp() - self::BACKLOG_SECONDS);
    }
}
