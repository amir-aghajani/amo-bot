<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Psr\Log\LoggerInterface;

/**
 * What the report group's buttons and replies share: who may act (admin(): a bot admin's own account, never anyone just
 * for being in the group, nor what a button's data says), changing a message's buttons, and the bot's word under
 * someone's message. Nothing here throws over Telegram: a failure is logged.
 */
final class GroupButtons
{
    /** To an admin who wrote as the group itself (anonymously): there is no account to authorise. */
    public const ANONYMOUS = 'پیام ناشناس پذیرفته نمی‌شود؛ حالت ناشناس (Remain anonymous) خود را در این گروه خاموش کنید و دوباره بنویسید.';

    public function __construct(
        private readonly BotApi $api,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The bot admin behind a Telegram account (role admin, not banned), or null for anyone else.
     *
     * @param array<string, mixed>|null $from
     */
    public static function admin(?array $from): ?User
    {
        if (!isset($from['id']) || ($from['is_bot'] ?? false) === true) {
            return null;
        }

        $user = User::query()->where('telegram_id', (int) $from['id'])->first();

        return $user !== null && $user->isAdmin() && !$user->isBanned() ? $user : null;
    }

    /**
     * The topic a group message is in (its message_thread_id), or null for one in no topic.
     *
     * @param array<string, mixed> $message
     */
    public static function threadOf(array $message): ?int
    {
        return ($message['is_topic_message'] ?? false) === true ? (int) ($message['message_thread_id'] ?? 0) : null;
    }

    /** The bot admin who pressed the button; null after telling anyone else why in a popup (`$notAdmin`). */
    public function presser(Update $update, string $notAdmin): ?User
    {
        $admin = self::admin($update->from());
        if ($admin === null) {
            $this->answer($update, $notAdmin, alert: true);
        }

        return $admin;
    }

    /** Answer the press: stop its spinner, with a toast or (`$alert`) a popup. */
    public function answer(Update $update, ?string $text = null, bool $alert = false): void
    {
        $this->api->answerCallbackQuery((string) $update->callbackId(), $text, $alert);
    }

    /**
     * Put these buttons under the pressed message; null takes them off.
     *
     * @param list<list<array<string, mixed>>>|null $rows
     */
    public function buttons(Update $update, ?array $rows): void
    {
        $this->set((int) $update->chatId(), (int) $update->messageId(), $rows);
    }

    /** Nothing is left to decide here: the pressed message loses its buttons and a popup says why. */
    public function dismiss(Update $update, string $why): void
    {
        $this->buttons($update, null);
        $this->answer($update, $why, alert: true);
    }

    /**
     * The message `$message` replies to, when that is one of the bot's own; null otherwise.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function botReplied(array $message): ?array
    {
        $replied = $message['reply_to_message'] ?? null;

        return is_array($replied) && (int) ($replied['from']['id'] ?? 0) === $this->api->botId() ? $replied : null;
    }

    /**
     * The bot admin who wrote `$message`; null after telling the writer why their message does nothing — written
     * anonymously (no account to authorise), or not a bot admin (`$notAdmin`).
     *
     * @param array<string, mixed> $message
     */
    public function writer(int $chatId, array $message, string $notAdmin): ?User
    {
        if (isset($message['sender_chat'])) {
            $this->sayUnder($chatId, $message, self::ANONYMOUS);

            return null;
        }

        $admin = self::admin(is_array($message['from'] ?? null) ? $message['from'] : null);
        if ($admin === null) {
            $this->sayUnder($chatId, $message, $notAdmin);
        }

        return $admin;
    }

    /**
     * Set a message's buttons; null takes them off. Buttons already as asked are fine.
     *
     * @param list<list<array<string, mixed>>>|null $rows
     */
    public function set(int $chatId, int $messageId, ?array $rows): void
    {
        try {
            $this->api->editMessageReplyMarkup($chatId, $messageId, ['inline_keyboard' => $rows ?? []]);
        } catch (TelegramApiException $e) {
            $this->logger->info('The buttons of message {message} in chat {chat} stayed: {error}', ['message' => $messageId, 'chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The bot's words (plain text) as a reply to `$replyTo` in topic `$thread`; the message's id, or null when Telegram
     * refused it.
     *
     * @param array<string, mixed>|null $markup
     */
    public function say(int $chatId, int $replyTo, ?int $thread, string $text, ?array $markup = null): ?int
    {
        $options = ['reply_parameters' => ['message_id' => $replyTo, 'allow_sending_without_reply' => true]];
        if ($thread !== null && $thread > 0) {
            $options['message_thread_id'] = $thread;
        }
        if ($markup !== null) {
            $options['reply_markup'] = $markup;
        }

        try {
            return (int) ($this->api->sendText($chatId, $text, $options)['message_id'] ?? 0);
        } catch (TelegramApiException $e) {
            $this->logger->warning('Could not write in group {chat}: {message}', ['chat' => $chatId, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The bot's words as a reply to someone's `$message`, in its topic.
     *
     * @param array<string, mixed> $message
     */
    public function sayUnder(int $chatId, array $message, string $text): void
    {
        $this->say($chatId, (int) ($message['message_id'] ?? 0), self::threadOf($message), $text);
    }
}
