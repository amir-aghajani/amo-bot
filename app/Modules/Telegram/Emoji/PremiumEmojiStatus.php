<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Modules\Settings\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * Whether the bot may send premium emoji (only while its owner has Telegram Premium), as last found out — by /emoji, or
 * by a message Telegram refused over one (BotApi). While a "no" is fresh (HOLD_SECONDS), messages go plain from the
 * start instead of paying a refused call each.
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

    /** Keep what the bot just found: whether its premium emoji went through. */
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
