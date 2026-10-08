<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Core\Support\FileCache;
use App\Core\Support\Picture;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Models\CustomEmoji;
use Psr\Log\LoggerInterface;

/**
 * The premium emoji the bot's texts and keyboard buttons can use: the ones an admin showed the bot (/emoji,
 * Handlers\CustomEmojiHandler), kept for the editors' picker — Telegram's id, the plain emoji it stands for (what goes
 * inside a `<tg-emoji>`, shown where the premium one cannot be), and how the panel can show it as Telegram does: a still
 * picture, the animation when it moves (a Lottie one — Telegram's gzipped .tgs — or a WebM video), and whether Telegram
 * paints it in the colour of the text around it. Pictures and animations are fetched from Telegram as the panel asks
 * — like a receipt, kept a short while for the reads that follow (Core\Support\FileCache), nothing kept for good. Whether
 * the bot may send them at all is PremiumEmojiStatus'.
 */
final class CustomEmojis
{
    /** The most the library keeps; the oldest go first. */
    public const MAX = 200;

    /** How Telegram draws one (its sticker's `is_animated` / `is_video`). */
    public const STATIC = 'static';
    public const ANIMATED = 'animated';
    public const VIDEO = 'video';

    /** Telegram's own type of a .tgs file: a Lottie animation, gzipped. */
    public const LOTTIE_MIME = 'application/x-tgsticker';

    /** @param FileCache $fetched Where a file fetched from Telegram is kept a while */
    public function __construct(
        private readonly BotApi $api,
        private readonly LoggerInterface $logger,
        private readonly FileCache $fetched,
    ) {}

    /**
     * Keep the premium emoji a message carried — each one's id and the plain emoji the message showed for it. Telegram is
     * asked for each one's sticker, for its own plain emoji and how to show it; when it cannot be asked, the message's
     * emoji stands and the rest is learned when the panel asks for the picture. Returns how many were new.
     *
     * @param list<array{id: string, emoji: string}> $found
     */
    public function remember(array $found): int
    {
        if ($found === []) {
            return 0;
        }

        $stickers = [];
        try {
            foreach (array_chunk(array_column($found, 'id'), Limits::CUSTOM_EMOJI_IDS) as $ids) {
                foreach ($this->api->getCustomEmojiStickers($ids) as $sticker) {
                    $stickers['id' . ($sticker['custom_emoji_id'] ?? '')] = $sticker;
                }
            }
        } catch (TelegramApiException $e) {
            $this->logger->warning('Premium emoji stickers not read: {message}', ['message' => $e->getMessage()]);
        }

        $new = 0;
        foreach ($found as ['id' => $id, 'emoji' => $emoji]) {
            $sticker = $stickers['id' . $id] ?? [];
            $row = CustomEmoji::query()->firstOrNew(['emoji_id' => $id]);
            $new += $row->exists ? 0 : 1;
            $row->fill(['emoji' => mb_substr(is_string($sticker['emoji'] ?? null) && $sticker['emoji'] !== '' ? $sticker['emoji'] : $emoji, 0, 32)]);
            self::learn($row, $sticker);
            // Seen again: it moves to the front of the picker.
            $row->updated_at = now();
            $row->save();
        }

        $keep = CustomEmoji::query()->latest('updated_at')->latest('id')->limit(self::MAX)->pluck('id')->all();
        CustomEmoji::query()->whereNotIn('id', $keep)->delete();

        return $new;
    }

    /**
     * The picker's emoji, the latest seen first — each with how Telegram draws it (`format` null: not known yet).
     *
     * @return list<array{id: string, emoji: string, format: string|null, repaint: bool, updated_at: string}>
     */
    public function present(): array
    {
        return CustomEmoji::query()->latest('updated_at')->latest('id')->get()
            ->map(static fn(CustomEmoji $row): array => [
                'id' => $row->emoji_id,
                'emoji' => $row->emoji,
                'format' => $row->format,
                'repaint' => $row->repaint,
                'updated_at' => $row->updated_at->toIso8601String(),
            ])
            ->values()->all();
    }

    /** Take one off the picker; a text that uses it keeps it. False when the picker did not have it. */
    public function forget(string $emojiId): bool
    {
        return CustomEmoji::query()->where('emoji_id', $emojiId)->delete() > 0;
    }

    /**
     * A still picture of a premium emoji for the panel — in the picker or the preview of a text that has one, kept or
     * not — with the type its bytes are, or null when Telegram has none (or cannot be asked).
     *
     * @return array{body: string, mime: string}|null
     */
    public function picture(string $emojiId): ?array
    {
        $row = CustomEmoji::query()->where('emoji_id', $emojiId)->first();

        try {
            $fileId = $row->file_id ?? ($this->sticker($emojiId, $row)['file_id'] ?? null);
            $body = $fileId === null ? null : $this->file($fileId);
        } catch (TelegramApiException $e) {
            $this->logger->info('Premium emoji {id} has no picture now: {message}', ['id' => $emojiId, 'message' => $e->getMessage()]);

            return null;
        }

        return $body === null ? null : ['body' => $body, 'mime' => Picture::typeOf($body)];
    }

    /**
     * How a premium emoji moves, for the panel — kept or not: its Lottie animation as Telegram has it (a .tgs: gzipped
     * JSON, LOTTIE_MIME) or its WebM video; null for a still one, or when Telegram has none (or cannot be asked).
     *
     * @return array{body: string, mime: string}|null
     */
    public function animation(string $emojiId): ?array
    {
        $row = CustomEmoji::query()->where('emoji_id', $emojiId)->first();
        if ($row?->format === self::STATIC) {
            return null;
        }

        try {
            $fileId = $row->animation_file_id ?? ($this->sticker($emojiId, $row)['animation_file_id'] ?? null);
            $body = $fileId === null ? null : $this->file($fileId);
        } catch (TelegramApiException $e) {
            $this->logger->info('Premium emoji {id} has no animation now: {message}', ['id' => $emojiId, 'message' => $e->getMessage()]);

            return null;
        }

        // Told apart by their first bytes: gzip (a .tgs) and EBML (WebM).
        return match (true) {
            $body === null => null,
            str_starts_with($body, "\x1f\x8b") => ['body' => $body, 'mime' => self::LOTTIE_MIME],
            str_starts_with($body, "\x1a\x45\xdf\xa3") => ['body' => $body, 'mime' => 'video/webm'],
            default => null,
        };
    }

    /**
     * A file Telegram keeps, by its id — kept a short while once fetched: the picker shows the same emoji again and again;
     * null when Telegram has none.
     *
     * @throws TelegramApiException
     */
    private function file(string $fileId): ?string
    {
        return $this->fetched->remember('telegram|' . $fileId, fn(): ?string => $this->api->fileBytes($fileId));
    }

    /**
     * Telegram's sticker of an emoji, as the panel needs it — learned onto its row when it is kept; empty when Telegram
     * has none.
     *
     * @return array{file_id?: string|null, animation_file_id?: string|null}
     * @throws TelegramApiException
     */
    private function sticker(string $emojiId, ?CustomEmoji $row): array
    {
        $sticker = $this->api->getCustomEmojiStickers([$emojiId])[0] ?? [];
        if ($sticker === []) {
            return [];
        }
        if ($row !== null) {
            self::learn($row, $sticker);
            $row->save();
        }

        return ['file_id' => self::pictureOf($sticker), 'animation_file_id' => self::animationOf($sticker)];
    }

    /**
     * What a row keeps of its sticker: a still picture (the one it had when the sticker offers none), how it moves and
     * the file that does, whether it is repainted. A sticker Telegram did not hand over changes nothing.
     *
     * @param array<string, mixed> $sticker
     */
    private static function learn(CustomEmoji $row, array $sticker): void
    {
        if ($sticker === []) {
            return;
        }

        $row->forceFill([
            'file_id' => self::pictureOf($sticker) ?? $row->file_id,
            'format' => match (true) {
                (bool) ($sticker['is_animated'] ?? false) => self::ANIMATED,
                (bool) ($sticker['is_video'] ?? false) => self::VIDEO,
                default => self::STATIC,
            },
            'animation_file_id' => self::animationOf($sticker),
            'repaint' => (bool) ($sticker['needs_repainting'] ?? false),
        ]);
    }

    /**
     * A still picture of the sticker: its thumbnail, or the sticker itself when it is a still one.
     *
     * @param array<string, mixed> $sticker
     */
    private static function pictureOf(array $sticker): ?string
    {
        $thumbnail = $sticker['thumbnail']['file_id'] ?? null;
        if (is_string($thumbnail) && $thumbnail !== '') {
            return $thumbnail;
        }

        return self::animationOf($sticker) === null ? self::fileOf($sticker) : null;
    }

    /**
     * The sticker itself when it moves (an animated or video one); null for a still one.
     *
     * @param array<string, mixed> $sticker
     */
    private static function animationOf(array $sticker): ?string
    {
        return ($sticker['is_animated'] ?? false) || ($sticker['is_video'] ?? false) ? self::fileOf($sticker) : null;
    }

    /** @param array<string, mixed> $sticker */
    private static function fileOf(array $sticker): ?string
    {
        $fileId = $sticker['file_id'] ?? null;

        return is_string($fileId) && $fileId !== '' ? $fileId : null;
    }
}
