<?php

declare(strict_types=1);

namespace App\Modules\Imports\Writers;

use App\Core\Exceptions\ValidationException;
use App\Modules\Imports\Enums\ProblemReason;
use App\Modules\Imports\Links;
use App\Modules\Imports\Records\ImportedChannel;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Channels\ChannelReference;
use App\Modules\Telegram\Channels\RequiredChannels;
use App\Modules\Telegram\Models\BotChannel;

/**
 * The channels stage: each channel the other bot made its customers join added as the bot's own required channels are
 * (RequiredChannels::add()) — Telegram asked which chat it is, the bot an admin there. One the shop requires already is
 * linked; one Telegram or the bot's rights refuse is named in the report in their words, to add by hand once fixed.
 */
final class ChannelWriter
{
    private const KIND = 'channels';

    public function __construct(
        private readonly RequiredChannels $channels,
        private readonly Links $links,
    ) {}

    public function write(Run $run, ImportedChannel $channel): void
    {
        if ($run->broughtBefore(Links::CHANNEL, self::KIND, $channel->key, BotChannel::class)) {
            return;
        }
        $existing = self::existing($channel->link);
        if ($existing !== null) {
            $this->links->add($run->import, Links::CHANNEL, $channel->key, $existing->id);
            $run->linked(self::KIND);

            return;
        }

        // What finds it again: the link the other bot checked, its title, and the link it sent customers to join by.
        $link = implode(' ', array_filter([Run::isolate($channel->link), $channel->title === null ? null : "«{$channel->title}»", $channel->inviteLink === null ? null : Run::isolate($channel->inviteLink)]));
        try {
            $made = $this->channels->add($channel->link);
        } catch (ValidationException $e) {
            $run->skipped(self::KIND, $channel->key, ProblemReason::ChannelRefused, $link . ': ' . Run::words($e));

            return;
        } catch (TelegramUnreachableException $e) {
            $run->skipped(self::KIND, $channel->key, ProblemReason::ChannelRefused, $link . ': ' . $e->getMessage());

            return;
        }
        $this->links->add($run->import, Links::CHANNEL, $channel->key, $made->id);
        $run->created(self::KIND);
    }

    /** The shop's required channel the link names already, by its id or its public handle; null for none. */
    private static function existing(string $link): ?BotChannel
    {
        $reference = ChannelReference::parse($link);
        if ($reference === null || $reference->isInviteLink()) {
            return null;
        }
        $chat = $reference->chat();

        return is_int($chat)
            ? BotChannel::query()->where('chat_id', $chat)->first()
            : BotChannel::query()->whereRaw('LOWER(username) = ?', [strtolower(ltrim($chat, '@'))])->first();
    }
}
