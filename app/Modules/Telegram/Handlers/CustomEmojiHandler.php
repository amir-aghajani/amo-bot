<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\EntityHtml;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Support\Persian;

/**
 * /emoji — an admin shows the bot premium emoji for its texts (routes/bot.php serves it to the bot's admins only): the
 * command asks for a message, and the admin sends one composed in Telegram the way a customer should see it (premium
 * emoji, formatting, `%variables%`). The bot keeps its premium emoji for the editors' picker (Emoji\CustomEmojis), sends
 * the message back written by itself — whether its premium emoji survive says whether the bot may use them (its owner
 * needs Telegram Premium): the last finding is forgotten first, so the bot asks afresh, and what it finds is kept
 * (PremiumEmojiStatus) — and answers with the message as a template (Telegram HTML, in a block a tap copies) to paste
 * into a text.
 */
final class CustomEmojiHandler implements Handler
{
    public const COMMAND = 'emoji';
    public const STATE = 'emoji';

    public function __construct(
        private readonly CustomEmojis $emojis,
        private readonly PremiumEmojiStatus $status,
    ) {}

    public function handle(Context $ctx): void
    {
        if ($ctx->update->isCommand()) {
            $ctx->session->enter(self::STATE);
            $ctx->reply(Messages::EMOJI_ASK);

            return;
        }

        $this->capture($ctx);
    }

    /** The admin's message: its premium emoji kept, the bot's own copy of it, and the template. */
    private function capture(Context $ctx): void
    {
        $message = $ctx->update->message() ?? [];
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $entities = array_values(array_filter((array) ($message['entities'] ?? $message['caption_entities'] ?? []), is_array(...)));

        $found = EntityHtml::premiumEmoji($text, $entities);
        if ($found === []) {
            $ctx->reply(Messages::EMOJI_NONE);

            return;
        }

        $new = $this->emojis->remember($found);
        $ctx->session->clear();
        $template = EntityHtml::of($text, $entities);

        // The bot's own copy: whether Telegram kept its premium emoji is whether the bot may use them.
        $this->status->forget();
        $kept = null;
        try {
            $kept = self::carriesPremiumEmoji($ctx->reply($template));
            $this->status->record($kept);
        } catch (TelegramApiException) {
            // Not sent at all: what the bot may use stays unknown; the emoji are kept all the same.
        }

        $summary = Messages::fill(Messages::EMOJI_SAVED, ['count' => Persian::number(count($found)), 'new' => Persian::number($new)]);
        if ($kept !== null) {
            $summary .= "\n\n" . ($kept ? Messages::EMOJI_WORKS : Messages::EMOJI_BLOCKED);
        }
        $withTemplate = $summary . "\n\n" . Messages::EMOJI_TEMPLATE . "\n<pre>" . htmlspecialchars($template, ENT_NOQUOTES) . '</pre>';

        $ctx->reply(TelegramHtml::visibleLength($withTemplate) <= Limits::MESSAGE ? $withTemplate : $summary . "\n\n" . Messages::EMOJI_TEMPLATE_LONG);
    }

    /** @param array<string, mixed> $message A message the bot sent, as Telegram answered it */
    private static function carriesPremiumEmoji(array $message): bool
    {
        foreach ((array) ($message['entities'] ?? []) as $entity) {
            if (is_array($entity) && ($entity['type'] ?? null) === 'custom_emoji') {
                return true;
            }
        }

        return false;
    }
}
