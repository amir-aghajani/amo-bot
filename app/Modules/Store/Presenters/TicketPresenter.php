<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Presenters\TicketFields;

/**
 * A customer's ticket as their website shows it: the shared fields (Support\Presenters\TicketFields), whether support
 * wrote since they last read it, and — opened — its conversation, each of the customer's own messages saying whether
 * support has read it. Support's messages never name who of support wrote them.
 */
final class TicketPresenter
{
    /** @return array<string, mixed> */
    public static function present(Ticket $ticket): array
    {
        return TicketFields::ticket($ticket) + ['unread' => $ticket->customer_unread];
    }

    /**
     * The ticket with its messages (loaded), the first first. A customer's message is `seen` once support read the ticket
     * after it was written (`support_read_at`) or answered after it; support's own messages say null.
     *
     * @return array<string, mixed>
     */
    public static function detail(Ticket $ticket): array
    {
        $messages = $ticket->messages->values();
        $answered = null;
        foreach ($messages as $index => $message) {
            if ($message->author === TicketAuthor::Support) {
                $answered = $index;
            }
        }
        $readAt = $ticket->support_read_at;

        return self::present($ticket) + [
            'rating_note' => $ticket->rating_note,
            'messages' => $messages->map(static fn(TicketMessage $message, int $index): array => TicketFields::message($message) + [
                'seen' => $message->author === TicketAuthor::Support ? null : ($answered !== null && $index < $answered)
                    || ($readAt !== null && $message->created_at !== null && $message->created_at->lte($readAt)),
            ])->all(),
        ];
    }
}
