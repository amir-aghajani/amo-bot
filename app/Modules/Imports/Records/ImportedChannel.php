<?php

declare(strict_types=1);

namespace App\Modules\Imports\Records;

/**
 * A channel the other bot asked its customers to join before using it. Added as the bot's own required channels are
 * («کانال‌های اجباری»): Telegram is asked which chat it is, and the bot must be an admin there.
 */
final class ImportedChannel
{
    public function __construct(
        public readonly string $key,
        /** What the shop's channel form takes: an @username, a t.me link or a -100… chat id. */
        public readonly string $link,
        /** Its title in the other bot's button; null for none (Telegram's then). */
        public readonly ?string $title = null,
        /** The link the other bot sent customers to join by; null for none. */
        public readonly ?string $inviteLink = null,
    ) {}
}
