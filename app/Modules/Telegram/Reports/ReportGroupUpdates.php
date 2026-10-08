<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;

/**
 * What else a group sends that concerns the report group — its connect command and its buttons have routes of their own
 * (routes/bot.php); the rest of a group's chatter is not the bot's business (as an admin there, it is sent every
 * message):
 *  - the bot's own membership changing (my_chat_member) — removed, demoted, given its rights back;
 *  - the group's new title;
 *  - a reply to one of a ticket's reports, support's answer to it (TicketReplies::answer());
 *  - a reply to the bot's prompt for a rejection's reason (ReceiptReview::reason()).
 * Each reply's reader says whether the reply was its own, by the message it answers as the bot recorded it — never by
 * that message's words —, a ticket's first: a reply to a ticket's report always reaches the ticket.
 */
final class ReportGroupUpdates implements GroupHandler
{
    public function __construct(
        private readonly ReportGroup $group,
        private readonly ReceiptReview $review,
        private readonly TicketReplies $tickets,
    ) {}

    public function handle(Update $update): void
    {
        if ($update->type() === 'my_chat_member') {
            $this->group->membershipChanged((array) $update->raw['my_chat_member']);

            return;
        }

        $message = $update->message();
        if ($message === null) {
            return;
        }
        if (isset($message['new_chat_title'])) {
            $this->group->renamed((int) ($message['chat']['id'] ?? 0), (string) $message['new_chat_title']);

            return;
        }

        if (!$this->tickets->answer($update)) {
            $this->review->reason($update);
        }
    }
}
