<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Models\BotChannel;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;

/**
 * Whether a customer has joined the required channels, asked of Telegram per channel and trusted for RECHECK_AFTER
 * seconds (a session mark keyed on the list). The rule holds while on, for everyone but bot admins. A channel Telegram
 * cannot answer for counts as joined and, the channel itself the reason, is flagged for the admin: a broken channel must
 * not lock the shop.
 */
final class ChannelMembership
{
    /** Seconds a confirmed membership is trusted before it is looked up again. */
    public const RECHECK_AFTER = 300;

    private const JOINED = ['creator', 'administrator', 'member'];
    private const SESSION_KEY = '_joined';

    public function __construct(
        private readonly BotSettings $settings,
        private readonly BotApi $api,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The channels the customer has still to join, in the order they are shown — none while the rule is off, for a bot
     * admin, or while a recent check holds (`$fresh` asks Telegram whatever the mark says: "عضو شدم"). A channel with no
     * way in (BotChannel::link()) is not asked: no one could join it.
     *
     * @return list<BotChannel>
     */
    public function missing(Context $ctx, bool $fresh = false): array
    {
        if (!$this->settings->joinRequired() || $ctx->isAdmin()) {
            return [];
        }

        $channels = BotChannel::ordered()->get()->filter(static fn(BotChannel $channel): bool => $channel->link() !== null)->values();
        if ($channels->isEmpty() || (!$fresh && $this->clearedRecently($ctx, $channels))) {
            return [];
        }

        $missing = array_values(array_filter($channels->all(), fn(BotChannel $channel): bool => !$this->isMember($channel, $ctx->user->telegram_id)));
        if ($missing === []) {
            $ctx->session->put(self::SESSION_KEY, ['list' => self::listKey($channels), 'at' => now()->getTimestamp()]);
        }

        return $missing;
    }

    /**
     * One channel asked of Telegram. Unanswered counts as joined; when the channel itself is the reason — not a moment's
     * outage, nor a refusal about this one customer (an id Telegram does not know there) —, it is flagged for the admin.
     */
    private function isMember(BotChannel $channel, int $telegramId): bool
    {
        try {
            $member = $this->api->getChatMember($channel->chat_id, $telegramId);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Membership check for channel {chat} failed: {message}', ['chat' => $channel->chat_id, 'message' => $e->getMessage()]);
            if ($channel->bot_is_admin && !$e->refusal()->isTransient() && !Refusal::aboutTheUser($e->getMessage())) {
                $channel->forceFill(['bot_is_admin' => false, 'checked_at' => now()])->save();
            }

            return true;
        }

        $status = (string) ($member['status'] ?? '');

        return in_array($status, self::JOINED, true) || ($status === 'restricted' && ($member['is_member'] ?? false) === true);
    }

    /** @param Collection<int, BotChannel> $channels */
    private function clearedRecently(Context $ctx, Collection $channels): bool
    {
        $mark = $ctx->session->get(self::SESSION_KEY);

        return is_array($mark)
            && ($mark['list'] ?? null) === self::listKey($channels)
            && (int) ($mark['at'] ?? 0) > now()->getTimestamp() - self::RECHECK_AFTER;
    }

    /** @param Collection<int, BotChannel> $channels */
    private static function listKey(Collection $channels): string
    {
        return implode(',', $channels->pluck('chat_id')->all());
    }
}
