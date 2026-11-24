<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Models\CustomEmoji;
use Psr\Log\LoggerInterface;

/**
 * Whether the bot may send premium emoji, found out the one way Telegram allows (no call tells it): the bot of the shop
 * on screen sends a short message with one to a chat it has, reads whether Telegram kept it (a custom_emoji entity) —
 * kept while the account that made the bot in @BotFather has Telegram Premium —, records it (PremiumEmojiStatus) and
 * deletes the message again.
 */
final class PremiumCheck
{
    public const NOTHING_TO_TEST = 'ربات هنوز ایموجی پرمیومی نمی‌شناسد که با آن بررسی کند: یک بار /emoji را در ربات بفرستید و بعد پیامی با ایموجی پرمیوم؛ همان پیام بررسی را هم انجام می‌دهد.';

    private const WORDS = '🔍 بررسی ایموجی پرمیوم: %s' . "\n" . 'این پیام آزمایشی است و خودکار پاک می‌شود.';

    public function __construct(
        private readonly BotApi $api,
        private readonly PremiumEmojiStatus $status,
        private readonly EmojiMap $map,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The premium emoji to test with: the map's first, else the newest the bot knows; null when it knows none.
     *
     * @return array{emoji: string, emoji_id: string}|null
     */
    public function sample(): ?array
    {
        $item = $this->map->stored()['items'][0] ?? null;
        if ($item !== null) {
            return $item;
        }
        $known = CustomEmoji::query()->latest('updated_at')->latest('id')->first();

        return $known === null ? null : ['emoji' => $known->emoji, 'emoji_id' => $known->emoji_id];
    }

    /**
     * Test in chat `$chat` with `$sample` (sample()'s): true or false as Telegram answered — recorded —, null when the
     * message could not be sent there at all (nothing new found: the chat is not the bot's, Telegram is out of reach —
     * what was found before stays).
     *
     * @param array{emoji: string, emoji_id: string} $sample
     */
    public function probe(int $chat, array $sample): ?bool
    {
        // A "no" held from before would send it plain from the start (BotApi): asked afresh.
        $was = $this->status->current();
        $this->status->forget();
        $words = sprintf(self::WORDS, '<tg-emoji emoji-id="' . $sample['emoji_id'] . '">' . htmlspecialchars($sample['emoji'], ENT_NOQUOTES) . '</tg-emoji>');

        try {
            $message = $this->api->sendMessage($chat, $words);
        } catch (TelegramApiException | TelegramUnreachableException $e) {
            $this->status->restore($was);
            $this->logger->info('Premium emoji test not sent to {chat}: {message}', ['chat' => $chat, 'message' => $e->getMessage()]);

            return null;
        }

        $kept = PremiumEmojiStatus::kept($message);
        $this->status->record($kept);

        try {
            if (is_int($message['message_id'] ?? null)) {
                $this->api->deleteMessage($chat, $message['message_id']);
            }
        } catch (TelegramApiException | TelegramUnreachableException) {
            // Left in the chat: a short note that says what it is.
        }

        return $kept;
    }
}
