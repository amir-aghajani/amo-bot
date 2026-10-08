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
 * Everything a handler needs for one update: the update itself, the API, the user and their conversation state — and
 * the ways it answers in that chat. A tap is answered once (answer()); one the handler leaves unanswered is
 * acknowledged by the dispatcher when it is done, and a handler that fails is reported by it — on the button while the
 * tap is unanswered, in a message once it was.
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
     * Edit the message the callback button belongs to (an edit to what it shows already is no change); a fresh message
     * otherwise — a message the customer sent, or one Telegram will not edit (too old, gone). A button on a photo (a QR
     * card) cannot have its message edited into text, so that message is replaced instead — deleted and the text sent
     * anew — and the chat keeps one message, not two.
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
            // The message is past editing; anything else — Telegram out of reach, the chat gone — a message fails alike.
            if (!$e->is(Refusal::BadRequest)) {
                throw $e;
            }
            $this->reply($text, $options);
        }
    }

    /**
     * Redraw only the buttons of the callback button's message — a switch that flipped — and leave its text as
     * it is.
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
     * A picture made on the fly in place of the callback button's message: edited into it (a text message
     * can take one since Bot API 10.3), or — when Telegram will not — the message replaced by it, so the
     * chat keeps one message, not two.
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
     * Answer a callback with a fresh message instead of editing the button's message: the old one is
     * deleted (best effort — Telegram refuses after 48 hours) and the text sent anew, so it lands at the
     * bottom of the chat like a notification would. For the end of a flow, not a step of it.
     *
     * @param array<string, mixed> $options sendMessage options (reply_markup etc.)
     * @return array<string, mixed>
     */
    public function replace(string $text, array $options = []): array
    {
        $this->delete();

        return $this->reply($text, $options);
    }

    /** Take the callback button's message off the screen (best effort, deleteMessage()). */
    public function delete(): void
    {
        $messageId = $this->update->messageId();
        if ($this->update->isCallback() && $messageId !== null) {
            $this->deleteMessage($messageId);
        }
    }

    /** Take the customer's own message off the screen — one that should not stay in the chat (a bot token they sent). */
    public function deleteIncoming(): void
    {
        $messageId = $this->update->messageId();
        if (!$this->update->isCallback() && $messageId !== null) {
            $this->deleteMessage($messageId);
        }
    }

    /**
     * Take a message of this chat off the screen, best effort: one too old (Telegram refuses after 48 hours) or gone
     * already stays as it is, and whatever comes next still goes out.
     */
    public function deleteMessage(int $messageId): void
    {
        $this->api->deleteMessage($this->chatId(), $messageId);
    }

    /** Whether the sender is one of the shop's people (role admin): privileged commands, exempt from the gates. */
    public function isAdmin(): bool
    {
        return $this->user->isAdmin();
    }

    /**
     * The tap's answer — a toast, or with `$alert` a popup the customer closes; without text it just stops the button's
     * spinner. Telegram takes one answer per tap, so only the first is sent (a tap too old by now is past answering).
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

    /** Whether the tap has had its answer — a failure after it is told in a message, the button takes no other. */
    public function answered(): bool
    {
        return $this->answered;
    }
}
