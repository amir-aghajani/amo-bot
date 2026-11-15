<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Modules\Telegram\Admin\AdminSeats;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Emoji\CustomEmojis;
use App\Modules\Telegram\Emoji\PremiumEmojiStatus;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\EntityHtml;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Update\Dispatcher;
use App\Support\Persian;

/**
 * /emoji (the bot's admins and whoever manages the shop from it — AdminSeats —; anyone else gets no answer, and a
 * message of theirs at its step is one at no step), or «✨ افزودن ایموجی تازه» in the management's premium emoji
 * (await()): the admin sends a message with premium emoji, composed as a customer should see it. The bot keeps the emoji
 * for the panel's picker (Emoji\CustomEmojis), sends the message back itself — whether the emoji survive tells whether
 * the bot may use them (PremiumEmojiStatus; its owner needs Telegram Premium) — and answers with the message as Telegram
 * HTML to paste into a text; asked from the management, its window turns into the way back to it.
 */
final class CustomEmojiHandler implements Handler
{
    public const COMMAND = 'emoji';
    public const STATE = 'emoji';

    /** The management's window the message was asked from, and its way back (await()), as the step keeps them. */
    private const WINDOW = 'emoji_window';
    private const BACK = 'emoji_back';

    public function __construct(
        private readonly CustomEmojis $emojis,
        private readonly PremiumEmojiStatus $status,
        private readonly AdminSeats $seats,
        private readonly Dispatcher $dispatcher,
    ) {}

    public function handle(Context $ctx): void
    {
        if (!$ctx->isAdmin() && $this->seats->of($ctx->user) === null) {
            // As if there were no such command; a message at its step (left by one who manages no more) is one at no step.
            if (!$ctx->update->isCommand()) {
                $this->dispatcher->atNoStep($ctx);
            }

            return;
        }
        if ($ctx->update->isCommand()) {
            $ctx->session->enter(self::STATE);
            $ctx->reply(Messages::EMOJI_ASK);

            return;
        }

        $this->capture($ctx);
    }

    /**
     * The chat waits for the message with premium emoji, asked from the management's window `$window` («✨ افزودن ایموجی
     * تازه»): once it is in, that window's «✖️ انصراف» becomes the way back (`$back`, its button's data).
     */
    public static function await(Context $ctx, int $window, string $back): void
    {
        $ctx->session->enter(self::STATE, [self::WINDOW => $window, self::BACK => $back]);
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
        $window = $ctx->session->get(self::WINDOW);
        $back = $ctx->session->get(self::BACK);
        $ctx->session->clear();
        if (is_int($window) && is_string($back)) {
            self::doneIn($ctx, $window, $back);
        }
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

    /**
     * The management's window the message was asked from, its question answered: a word that the result is below and
     * «⚙️ بازگشت به مدیریت» in place of «✖️ انصراف» (best effort: one too old to edit stays as it was).
     */
    private static function doneIn(Context $ctx, int $window, string $back): void
    {
        try {
            $ctx->api->editMessageText($ctx->chatId(), $window, "<b>✨ افزودن ایموجی تازه</b>\n✅ پیام رسید؛ نتیجه زیر همین پیام است.", [
                'reply_markup' => ['inline_keyboard' => [[['text' => '⚙️ بازگشت به مدیریت', 'callback_data' => $back]]]],
            ]);
        } catch (TelegramApiException | TelegramUnreachableException) {
            // The window is gone or past editing: the result below says it all.
        }
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
