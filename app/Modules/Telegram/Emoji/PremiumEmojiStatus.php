<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Modules\Settings\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * Whether the bot may send premium emoji — Telegram lets it only while its owner (the account that made it in
 * @BotFather) has Telegram Premium — as the bot last found out, each bot for itself: /emoji asks (the bot sends the
 * admin's message back and looks at what survived), and a message Telegram turns down over one says no too (BotApi).
 * While a "no" is fresh, messages go with the plain emoji from the start instead of paying a refused call each; /emoji
 * forgets it before asking again, so a Premium bought meanwhile is seen at once. The editors warn while it says no.
 */
final class PremiumEmojiStatus
{
    /** How long a "no" holds before premium emoji are tried again. */
    public const HOLD_SECONDS = 600;

    /** The settings row: {ok: bool, at: ISO time}. */
    private const KEY = 'bot.premium_emoji';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{ok: bool, checked_at: string}|null What the bot last found; null before it ever asked. */
    public function current(): ?array
    {
        $status = $this->settings->get(self::KEY);

        return is_array($status) && isset($status['ok'], $status['at']) ? ['ok' => (bool) $status['ok'], 'checked_at' => (string) $status['at']] : null;
    }

    public function record(bool $ok): void
    {
        $this->settings->set(self::KEY, ['ok' => $ok, 'at' => now()->toIso8601String()]);
    }

    /** Forget what was found — the next message tries premium emoji again. */
    public function forget(): void
    {
        $this->settings->forget(self::KEY);
    }

    /** Whether the bot was told no within the hold: send the plain emoji from the start. */
    public function refusedLately(): bool
    {
        $status = $this->current();

        return $status !== null && !$status['ok'] && Carbon::parse($status['checked_at'])->addSeconds(self::HOLD_SECONDS)->isFuture();
    }
}
