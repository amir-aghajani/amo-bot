<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Update\Update;

/**
 * A bot admin's reply, in the report group, to any of a ticket's reports is support's answer to it: the report replied to
 * found by the group and its message (report_messages, the ticket it names — `ticket_id`), its words — a text, or a photo
 * with its caption — the answer, written as a panel writes one (Tickets::answer(): the ticket answered, the customer told
 * on every door they have), the admin's @username or Telegram id its reviewer. The bot answers the admin under their
 * reply: sent, or why not — not a bot admin, written anonymously, no words. A ticket's reports are kept while it is not
 * closed (ReportSender::pruneTickets()); a reply in the tickets topic to a message of the bot's that names no ticket — its
 * own word under an earlier answer, a report of a ticket closed long ago — is told where an answer goes. A reply to
 * anything else is none of its business.
 */
final class TicketReplies
{
    private const SENT = '✅ پاسخ برای مشتری فرستاده شد.';
    private const NOT_ADMIN = 'فقط مدیرهای ربات می‌توانند به تیکت پاسخ دهند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.';
    private const NO_WORDS = 'پاسخ را به صورت متن، یا تصویر همراه توضیح (کپشن) بفرستید.';
    private const NO_CAPTION = 'همراه تصویر توضیح هم بنویسید (کپشن)؛ مشتری پاسخ را با آن می‌خواند.';
    private const NOT_FOUND = 'این تیکت پیدا نشد.';
    private const NO_TICKET = 'این پیام به تیکتی وصل نیست و پاسخی فرستاده نشد؛ روی گزارش همان تیکت ریپلای کنید، یا از صفحه «پشتیبانی» در پنل پاسخ دهید.';

    public function __construct(
        private readonly BotApi $api,
        private readonly GroupButtons $groupButtons,
        private readonly Tickets $tickets,
        private readonly ReportTopics $topics,
    ) {}

    /**
     * A message in a group that replies to one of a ticket's reports: answered as support's word. False when it replies
     * to nothing of a ticket's (it is none of this class's business).
     */
    public function answer(Update $update): bool
    {
        $message = $update->raw['message'] ?? null;
        $replied = is_array($message) ? ($message['reply_to_message'] ?? null) : null;
        if (!is_array($replied) || (int) ($replied['from']['id'] ?? 0) !== $this->api->botId()) {
            return false;
        }
        $chatId = (int) $update->chatId();
        $reply = fn(string $text) => $this->groupButtons->say($chatId, (int) ($message['message_id'] ?? 0), GroupButtons::threadOf($message), $text);
        $ticketId = ReportMessage::query()->where('chat_id', $chatId)->where('message_id', (int) ($replied['message_id'] ?? 0))->latest('id')->value('ticket_id');
        if ($ticketId === null) {
            if (!$this->inTicketsTopic($chatId, $message, $replied)) {
                return false;
            }
            // An answer meant for a customer is never lost without a word.
            $reply(self::NO_TICKET);

            return true;
        }

        // An admin writing as the group (anonymously) has no account to authorise.
        if (isset($message['sender_chat'])) {
            $reply(GroupButtons::ANONYMOUS);

            return true;
        }
        $admin = GroupButtons::admin($update->from());
        if ($admin === null) {
            $reply(self::NOT_ADMIN);

            return true;
        }

        $photo = $update->photo();
        $words = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if ($words === '') {
            $reply($photo !== null ? self::NO_CAPTION : self::NO_WORDS);

            return true;
        }
        if ($photo === null && !isset($message['text'])) {
            // A file, a voice, a sticker with words under it: only a picture comes with an answer.
            $reply(self::NO_WORDS);

            return true;
        }

        $ticket = Ticket::query()->with(['user', 'subscription'])->find((int) $ticketId);
        if ($ticket === null) {
            $reply(self::NOT_FOUND);

            return true;
        }

        try {
            $this->tickets->answer($ticket, Actor::groupAdmin($admin), ['body' => $words], $photo !== null ? Attachment::telegram((string) ($photo['file_id'] ?? '')) : null);
        } catch (ValidationException $e) {
            $reply(array_values($e->errors())[0][0] ?? $e->getMessage());

            return true;
        }
        $reply(self::SENT);

        return true;
    }

    /**
     * Whether a message of a person's is in the connected group's tickets topic, replying to one of the bot's messages
     * there — not to the topic's own first one, which every message written in the topic replies to.
     *
     * @param array<string, mixed> $message
     * @param array<string, mixed> $replied
     */
    private function inTicketsTopic(int $chatId, array $message, array $replied): bool
    {
        return ($message['from']['is_bot'] ?? false) !== true
            && (int) ($replied['message_id'] ?? 0) !== GroupButtons::threadOf($message)
            && !isset($replied['forum_topic_created'])
            && $this->topics->holds(Topic::Tickets, $chatId, $message);
    }
}
