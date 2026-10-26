<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Notifications\Models\Notification;
use App\Modules\Telegram\Texts\WebText;

/**
 * A notice as the customer's website shows it: its kind, its text as plain text and as safe HTML (Telegram\Texts\WebText),
 * what it is about (an order, a service or a ticket, for a link), whether it was read, and when it came.
 */
final class NotificationPresenter
{
    /** @return array{id: int, type: string, text: string, html: string, subject: array{type: string, id: int}|null, read: bool, created_at: string|null} */
    public static function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type->value,
            'text' => WebText::plain($notification->text),
            'html' => WebText::html($notification->text),
            'subject' => $notification->subject_type === null || $notification->subject_id === null
                ? null
                : ['type' => $notification->subject_type->value, 'id' => $notification->subject_id],
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
