<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Presenters\TicketFields;

/**
 * A customer's ticket as their website shows it: the shared fields (Support\Presenters\TicketFields), whether support
 * wrote since they last read it, and — opened — its conversation. Support's messages never name who of support wrote them.
 */
final class TicketPresenter
{
    /** @return array<string, mixed> */
    public static function present(Ticket $ticket): array
    {
        return TicketFields::ticket($ticket) + ['unread' => $ticket->customer_unread];
    }

    /** @return array<string, mixed> The ticket with its messages (loaded), the first first. */
    public static function detail(Ticket $ticket): array
    {
        return self::present($ticket) + [
            'rating_note' => $ticket->rating_note,
            'messages' => $ticket->messages->map(static fn(TicketMessage $message): array => TicketFields::message($message))->values()->all(),
        ];
    }
}
