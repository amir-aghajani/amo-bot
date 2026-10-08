<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

/**
 * What the admin pasted to name a channel, reduced to something getChat accepts: "@username" for a
 * public one (from "https://t.me/name", "t.me/name", "@name" or "name") or the numeric chat id of a
 * private one ("-1001234567890"). A private invite link ("t.me/+…", "t.me/joinchat/…") is recognised
 * but cannot be looked up — the Bot API has no call for it — so it is reported as such.
 */
final class ChannelReference
{
    private function __construct(
        private readonly int|string $chat,
        private readonly bool $inviteLink,
    ) {}

    public static function parse(string $input): ?self
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('/^-?\d{5,20}$/', $input) === 1) {
            return new self((int) $input, false);
        }

        $path = preg_replace('~^(?:https?://)?(?:www\.)?(?:t(?:elegram)?\.me|telegram\.dog)/~i', '', $input);
        if (!is_string($path)) {
            return null;
        }
        $path = rtrim($path, '/');

        if (preg_match('~^(?:\+|joinchat/)[\w-]+$~', $path) === 1) {
            return new self($path, true);
        }

        $handle = ltrim($path, '@');
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $handle) === 1) {
            return new self('@' . $handle, false);
        }

        return null;
    }

    /** The chat_id parameter for the Bot API. */
    public function chat(): int|string
    {
        return $this->chat;
    }

    public function isInviteLink(): bool
    {
        return $this->inviteLink;
    }
}
