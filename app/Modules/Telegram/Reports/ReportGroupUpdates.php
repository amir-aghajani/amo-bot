<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Reports;

use App\Modules\Telegram\Update\GroupHandler;
use App\Modules\Telegram\Update\Update;

/**
 * A group's other updates (as an admin there, the bot gets every message): the bot's membership changing, the group's
 * new title, a reply answering a ticket (TicketReplies), and a reply giving a receipt's rejection its reason
 * (ReceiptReview::reason()). Ticket replies are asked first; the rest of the chatter is ignored.
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
