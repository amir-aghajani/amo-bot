<?php

declare(strict_types=1);

namespace App\Modules\Support\Presenters;

use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;

/**
 * What every screen says of a ticket and of its messages, said once: the panels' (Support\Services\TicketDirectory) and
 * the customer's website's (Store\Presenters\TicketPresenter) each add their own to it — the panels who the customer is,
 * how many messages, who of support wrote each and where; the website whether support wrote since the customer read it.
 */
final class TicketFields
{
    /**
     * A ticket: what it is about — its subject, the customer's service by its name on the panel —, where it stands and
     * since when it is closed, its latest activity, its rating.
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
            'last_message_at' => ($ticket->last_message_at ?? $ticket->created_at)->toIso8601String(),
            'rating' => $ticket->rating,
            'created_at' => $ticket->created_at->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }

    /**
     * A message: whose, its words, its picture — its name, and whether it is there to show still (an uploaded one goes a
     * while after its ticket closed) — and when.
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
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }
}
