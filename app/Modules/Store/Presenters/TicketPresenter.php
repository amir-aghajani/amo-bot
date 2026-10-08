<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Presenters\TicketFields;

/**
 * A support ticket of the customer's, as their website shows it: what every screen says of it (Support\Presenters\
 * TicketFields: what it is about, where it stands — and since when it is closed —, their rating), and whether support
 * wrote since they last read it — and, opened, its conversation, support's messages never saying who of support wrote
 * them (the customer reads «پشتیبانی»).
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
