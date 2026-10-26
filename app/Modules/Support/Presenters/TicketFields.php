<?php

declare(strict_types=1);

namespace App\Modules\Support\Presenters;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;

/**
 * The fields of a ticket and its messages that every screen shows, defined once: the panels' (Services\TicketDirectory)
 * and the website's (Store\Presenters\TicketPresenter) presenters each add their own.
 */
final class TicketFields
{
    /**
     * A ticket: its subject, the service it is about (by its name on the panel), its status and when it closed, its
     * latest activity and its rating.
     *
     * @return array<string, mixed>
     */
    public static function ticket(Ticket $ticket): array
    {
        $service = $ticket->subscription;

        return [
            'id' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'subscription' => $service === null ? null : ['id' => $service->id, 'name' => $service->remote_name],
            'last_message_at' => ($ticket->last_message_at ?? $ticket->created_at)?->toIso8601String(),
            'rating' => $ticket->rating,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }

    /**
     * A message: whose, its text, its picture (its name, and whether it is still kept to show) and when.
     *
     * @return array<string, mixed>
     */
    public static function message(TicketMessage $message): array
    {
        return [
            'id' => $message->id,
            'author' => $message->author->value,
            'body' => $message->body,
            'attachment' => $message->hasPicture() ? ['name' => (string) $message->attachment_name, 'kept' => $message->keepsPicture()] : null,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
