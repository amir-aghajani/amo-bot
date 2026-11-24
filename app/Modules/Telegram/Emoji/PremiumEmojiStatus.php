<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Modules\Settings\Services\Settings;
use Illuminate\Support\Carbon;

/**
 * Whether the bot may send premium emoji (only while its owner has Telegram Premium), as last found out — by /emoji, by
 * the check (PremiumCheck), or by a message with some that Telegram refused over them or sent without them (BotApi).
 * While a "no" is fresh (HOLD_SECONDS), messages go plain from the start instead of paying a refused call each.
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

    /**
     * Put back what was found before (current()'s, as of when it was found) — nothing, when nothing was.
     *
     * @param array{ok: bool, checked_at: string}|null $was
     */
    public function restore(?array $was): void
    {
        if ($was === null) {
            $this->forget();

            return;
        }
        $this->settings->set(self::KEY, ['ok' => $was['ok'], 'at' => $was['checked_at']]);
    }

    /** Whether the bot was told no within the hold: send the plain emoji from the start. */
    public function refusedLately(): bool
    {
        $status = $this->current();

        return $status !== null && !$status['ok'] && Carbon::parse($status['checked_at'])->addSeconds(self::HOLD_SECONDS)->isFuture();
    }

    /**
     * Whether Telegram kept a premium emoji in a message the bot sent (a custom_emoji entity in its text or caption): it
     * drops them silently while the bot may not send them.
     *
     * @param array<string, mixed> $message A message the bot sent, as Telegram answered it
     */
    public static function kept(array $message): bool
    {
        foreach ((array) ($message['entities'] ?? $message['caption_entities'] ?? []) as $entity) {
            if (is_array($entity) && ($entity['type'] ?? null) === 'custom_emoji') {
                return true;
            }
        }

        return false;
    }
}
