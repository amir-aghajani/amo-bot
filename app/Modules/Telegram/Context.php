<?php

declare(strict_types=1);

namespace App\Modules\Telegram;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Session\ChatSession;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;

/**
 * What a handler gets for one update: the update, the API, the customer and their session, and the ways to answer in
 * that chat (reply, edit in place, replace, delete). Telegram takes one answer per tap; answer() sends only the first.
 */
final class Context
{
    private bool $answered = false;

    public function __construct(
        public readonly Update $update,
        public readonly BotApi $api,
        public readonly User $user,
        public readonly ChatSession $session,
    ) {}

    /** The chat being served — the dispatcher only serves updates that have one. */
    public function chatId(): int
    {
        return $this->update->chatId() ?? throw new \LogicException("Update {$this->update->id()} has no chat.");
    }

    /**
     * @param array<string, mixed> $options sendMessage options (reply_markup etc.)
     * @return array<string, mixed>
     */
    public function reply(string $text, array $options = []): array
    {
        return $this->api->sendMessage($this->chatId(), $text, $options);
    }

    /**
     * Show `$text` in place of the tapped button's message; a new message for a message update or one Telegram will not
     * edit. A photo (a QR card) cannot become text, so it is replaced: deleted and sent anew, keeping one message.
     *
     * @param array<string, mixed> $options
     */
    public function edit(string $text, array $options = []): void
    {
        $messageId = $this->update->messageId();

        if (!$this->update->isCallback() || $messageId === null) {
            $this->reply($text, $options);

            return;
        }
        if ($this->update->hasMedia()) {
            $this->replace($text, $options);

            return;
        }

        try {
            $this->api->editMessageText($this->chatId(), $messageId, $text, $options);
        } catch (TelegramApiException $e) {
            // Past editing: send it instead. Anything else (Telegram out of reach, the chat gone) would fail a message too.
            if (!$e->is(Refusal::BadRequest)) {
                throw $e;
            }
            $this->reply($text, $options);
        }
    }

    /**
     * Redraw only the buttons of the tapped message (a switch that flipped), leaving its text.
     *
     * @param array<string, mixed> $replyMarkup
     */
    public function editKeyboard(array $replyMarkup): void
    {
        $messageId = $this->update->messageId();
        if ($this->update->isCallback() && $messageId !== null) {
            $this->api->editMessageReplyMarkup($this->chatId(), $messageId, $replyMarkup);
        }
    }

    /**
     * A picture in place of the tapped message: edited in (Bot API 10.3 lets a text message take media), or the
     * message replaced by it when Telegram refuses.
     *
     * @param array<string, mixed> $options sendPhoto options (caption, reply_markup)
     */
    public function editPhoto(string $bytes, string $filename, array $options = []): void
    {
        $messageId = $this->update->messageId();

        if ($this->update->isCallback() && $messageId !== null) {
            try {
                $this->api->editMessageMedia($this->chatId(), $messageId, $bytes, $filename, $options);

                return;
            } catch (TelegramApiException $e) {
                // An older Bot API server, a message too old or gone: the picture takes its place instead.
                if (!$e->is(Refusal::BadRequest)) {
                    throw $e;
                }
            }
        }

        $this->delete();
        $this->api->sendPhoto($this->chatId(), $bytes, $filename, $options);
    }

    /**
     * The tapped message deleted (best effort) and `$text` sent anew, landing at the bottom of the chat like a
     * notification — for the end of a flow, not a step of it.
     *
     * @param array<string, mixed> $options sendMessage options (reply_markup etc.)
     * @return array<string, mixed>
     */
    public function replace(string $text, array $options = []): array
    {
        $this->delete();

        return $this->reply($text, $options);
    }

    /**
     * `$text` sent anew in place of the tapped message — for a message that must be sent, never edited in (one protected
     * from forwarding: Telegram protects a message only as it is sent). The tapped message is deleted or, past Telegram's
     * 48 hours for deleting, left without its buttons, so nothing on it is tapped again.
     *
     * @param array<string, mixed> $options sendMessage options (reply_markup, protect_content etc.)
     * @return array<string, mixed>
     */
    public function resend(string $text, array $options = []): array
    {
        $messageId = $this->update->messageId();
        if ($this->update->isCallback() && $messageId !== null && !$this->api->deleteMessage($this->chatId(), $messageId)) {
            try {
                $this->api->editMessageReplyMarkup($this->chatId(), $messageId, ['inline_keyboard' => []]);
            } catch (TelegramApiException $e) {
                // Gone already: nothing left to tap. Anything else (Telegram out of reach) would fail the message too.
                if (!$e->is(Refusal::BadRequest)) {
                    throw $e;
                }
            }
        }

        return $this->reply($text, $options);
    }

    /** Delete the tapped message (best effort). */
    public function delete(): void
    {
        $messageId = $this->update->messageId();
        if ($this->update->isCallback() && $messageId !== null) {
            $this->deleteMessage($messageId);
        }
    }

    /** Delete the customer's own message — one that must not stay in the chat (a bot token they sent). */
    public function deleteIncoming(): void
    {
        $messageId = $this->update->messageId();
        if (!$this->update->isCallback() && $messageId !== null) {
            $this->deleteMessage($messageId);
        }
    }

    /** Delete a message of this chat, best effort: one too old (over 48 hours) or gone already is left as it is. */
    public function deleteMessage(int $messageId): void
    {
        $this->api->deleteMessage($this->chatId(), $messageId);
    }

    /**
     * The album (Telegram's media group) the customer's message came in, if any: Telegram sends an album a picture at a
     * time, each an update of its own.
     */
    public function album(): ?string
    {
        $album = $this->update->isCallback() ? null : ($this->update->message() ?? [])['media_group_id'] ?? null;

        return is_scalar($album) ? (string) $album : null;
    }

    /** Whether the sender is a bot admin (role admin): admin-only commands, and exempt from the gates. */
    public function isAdmin(): bool
    {
        return $this->user->isAdmin();
    }

    /**
     * Answer the tap: a toast, or with `$alert` a popup; without text it only stops the spinner. Only the first answer
     * is sent — Telegram takes one per tap.
     */
    public function answer(?string $text = null, bool $alert = false): void
    {
        $id = $this->update->callbackId();
        if ($id === null || $this->answered) {
            return;
        }

        $this->answered = true;
        $this->api->answerCallbackQuery($id, $text, $alert);
    }

    /**
     * A refusal's plain words (a popup's text): in a popup on the button tapped, the screen left as it was — or, for
     * something typed at a step (no button to answer, so a popup would never show), a message of its own with
     * `$options`, the step over.
     *
     * @param array<string, mixed> $options sendMessage options (reply_markup etc.)
     */
    public function alert(string $text, array $options = []): void
    {
        if ($this->update->isCallback()) {
            $this->answer($text, alert: true);

            return;
        }

        $this->session->clear();
        $this->reply(htmlspecialchars($text), $options);
    }

    /** Whether the tap was answered; a failure after that is reported in a message instead. */
    public function answered(): bool
    {
        return $this->answered;
    }
}
