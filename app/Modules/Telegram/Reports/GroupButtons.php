<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Users\Models\User;
use Psr\Log\LoggerInterface;

/**
 * The buttons of the bot's messages in the report group — a receipt's review (ReceiptReview), a failed delivery's retry
 * (DeliveryRetry), a request to become an agent (AgencyReview), a ticket's close (TicketClose) — and who may press them,
 * or answer a ticket by a reply (TicketReplies): the bot's admins (`users.role` admin, not banned), by the account that
 * pressed or wrote, never by being in the group or by what a button says — who decides is that account
 * (Auth\Actor::groupAdmin(), its name as Auth\Services\Reviewers::forAdmin() keeps it). Changing or taking off a
 * message's buttons, and the bot's words to whoever pressed or wrote (say()), never throw: buttons already as asked are
 * fine, anything else is logged.
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
     * The bot's admin behind a Telegram account, or null for anyone else.
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
     * The topic a message of the group is in (its message_thread_id), or null for one in no topic.
     *
     * @param array<string, mixed> $message
     */
    public static function threadOf(array $message): ?int
    {
        return ($message['is_topic_message'] ?? false) === true ? (int) ($message['message_thread_id'] ?? 0) : null;
    }

    /** @param list<list<array<string, mixed>>>|null $rows null takes the buttons off */
    public function set(int $chatId, int $messageId, ?array $rows): void
    {
        try {
            $this->api->editMessageReplyMarkup($chatId, $messageId, ['inline_keyboard' => $rows ?? []]);
        } catch (TelegramApiException $e) {
            $this->logger->info('The buttons of message {message} in chat {chat} stayed: {error}', ['message' => $messageId, 'chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The bot's words in the group, as written, under `$replyTo` in its topic (`$thread`): what a reply or a press came
     * to, a prompt for a reason. The message's id, or null when Telegram would not take it.
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
}
