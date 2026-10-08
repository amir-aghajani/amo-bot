<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Models\BotChannel;
use Illuminate\Database\Eloquent\Collection;
use Psr\Log\LoggerInterface;

/**
 * Whether a customer has joined the channels the bot requires (RequiredChannels' list), asked of Telegram per channel
 * — and trusted for RECHECK_AFTER seconds once every one checked out, a mark in the chat's session keyed on the list,
 * so a busy flow does not ask on every tap. The rule holds while the admin has it on, for everyone but the bot's
 * admins. A channel Telegram cannot answer for (the bot lost its admin rights, the channel is gone) counts as joined
 * and is flagged for the admin: a broken channel must not lock the whole shop.
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
     * admin, or while a recent check holds (`$fresh` asks Telegram whatever the mark says: "عضو شدم").
     *
     * @return list<BotChannel>
     */
    public function missing(Context $ctx, bool $fresh = false): array
    {
        if (!$this->settings->joinRequired() || $ctx->isAdmin()) {
            return [];
        }

        $channels = BotChannel::ordered()->get();
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
     * One channel, asked of Telegram. One it cannot answer for counts as joined; when the channel is the reason (the
     * bot lost its rights there, the channel is gone) — not Telegram out of reach for a moment — the admin sees it flagged.
     */
    private function isMember(BotChannel $channel, int $telegramId): bool
    {
        try {
            $member = $this->api->getChatMember($channel->chat_id, $telegramId);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Membership check for channel {chat} failed: {message}', ['chat' => $channel->chat_id, 'message' => $e->getMessage()]);
            if ($channel->bot_is_admin && !$e->refusal()->isTransient()) {
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
