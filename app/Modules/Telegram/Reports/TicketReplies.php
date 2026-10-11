<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Update\Update;

/**
 * A bot admin's reply in the report group to any of a ticket's reports is support's answer to that ticket
 * (Tickets::answer(), as from a panel). The ticket is found by the message replied to, as the bot recorded it — never by
 * that message's words. The admin is told under their reply whether it went, or why not. A reply in the tickets topic
 * to a bot message that names no ticket is told where answers go, so an answer is never lost without a word.
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
        private readonly GroupButtons $groupButtons,
        private readonly Tickets $tickets,
        private readonly ReportTopics $topics,
    ) {}

    /**
     * Answer the ticket a group message replies to; false when it replies to nothing of a ticket's (not this class's
     * business). The answer is a text, or a photo with a caption.
     */
    public function answer(Update $update): bool
    {
        $message = $update->raw['message'] ?? null;
        $replied = is_array($message) ? $this->groupButtons->botReplied($message) : null;
        if ($replied === null) {
            return false;
        }
        $chatId = (int) $update->chatId();
        $ticketId = ReportMessage::query()->where('chat_id', $chatId)->where('message_id', (int) ($replied['message_id'] ?? 0))->latest('id')->value('ticket_id');
        if ($ticketId === null) {
            if (!$this->inTicketsTopic($chatId, $message, $replied)) {
                return false;
            }
            $this->groupButtons->sayUnder($chatId, $message, self::NO_TICKET);

            return true;
        }

        $admin = $this->groupButtons->writer($chatId, $message, self::NOT_ADMIN);
        if ($admin === null) {
            return true;
        }

        $photo = $update->photo();
        $words = trim((string) ($update->text() ?? $update->caption()));
        if ($words === '' || ($photo === null && $update->text() === null)) {
            // An answer needs words, and only a picture may come with them: a file or a voice, caption or not, is refused.
            $this->groupButtons->sayUnder($chatId, $message, $photo !== null ? self::NO_CAPTION : self::NO_WORDS);

            return true;
        }

        $ticket = Ticket::query()->with(['user', 'subscription'])->find((int) $ticketId);
        if ($ticket === null) {
            $this->groupButtons->sayUnder($chatId, $message, self::NOT_FOUND);

            return true;
        }

        try {
            $this->tickets->answer($ticket, Actor::groupAdmin($admin), ['body' => $words], $photo !== null ? Attachment::telegram((string) ($photo['file_id'] ?? '')) : null);
        } catch (ValidationException $e) {
            $this->groupButtons->sayUnder($chatId, $message, array_values($e->errors())[0][0] ?? $e->getMessage());

            return true;
        }
        $this->groupButtons->sayUnder($chatId, $message, self::SENT);

        return true;
    }

    /**
     * Whether a person's message is in the connected group's tickets topic, replying to one of the bot's messages there
     * — not to the topic's own first message, which every message written in the topic replies to.
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
