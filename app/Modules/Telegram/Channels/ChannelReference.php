<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Channels;

use App\Support\Persian;

/**
 * What the admin pasted to name a channel, as getChat takes it: "@username" for a public one, or a private one's numeric
 * id. A private invite link is recognised but cannot be looked up (the Bot API has no call for it).
 */
final class ChannelReference
{
    private function __construct(
        private readonly int|string $chat,
        private readonly bool $inviteLink,
    ) {}

    /** What the admin pasted, read; null when it names no channel. */
    public static function parse(string $input): ?self
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // A private one's id, as copied or as typed on a Persian keyboard.
        $id = Persian::latinDigits($input);
        if (preg_match('/^-?\d{5,20}$/', $id) === 1) {
            return new self((int) $id, false);
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
