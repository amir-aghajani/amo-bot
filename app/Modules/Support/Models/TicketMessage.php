<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message of a ticket: the customer's or support's words, where it was written, who of support wrote it, and at most
 * one picture — sent in Telegram (its file id; Telegram keeps it) or uploaded (kept in the tickets' folder until the
 * ticket has been closed a while: TicketAttachments::prune() keeps its name, so the message still says it had one).
 *
 * @property int $id
 * @property int $ticket_id
 * @property TicketAuthor $author
 * @property string|null $reviewer Who of support wrote it (the actor's name); null for the customer's
 * @property string $body
 * @property string|null $attachment_file_id Its picture, sent in Telegram: the file id
 * @property string|null $attachment_path Its picture, uploaded: its name in the tickets' folder; null once it is no longer kept
 * @property string|null $attachment_name Its picture's name, as its sender's device gave it or one made up; null without a picture
 * @property TicketChannel $channel
 * @property string|null $request_key The Idempotency-Key of support's answer that wrote it; null for every other message
 * @property Carbon|null $created_at
 */
class TicketMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_messages';

    protected $fillable = ['ticket_id', 'author', 'reviewer', 'body', 'attachment_file_id', 'attachment_path', 'attachment_name', 'channel', 'request_key'];

    /** @var array<string, string> */
    protected $casts = [
        'ticket_id' => 'integer',
        'author' => TicketAuthor::class,
        'channel' => TicketChannel::class,
    ];

    /** It came with a picture, kept still or not (its name stays). */
    public function hasPicture(): bool
    {
        return $this->attachment_name !== null || $this->keepsPicture();
    }

    /** Its picture is there to show: Telegram's (its file id), or an upload the shop still keeps. */
    public function keepsPicture(): bool
    {
        return $this->attachment_file_id !== null || $this->attachment_path !== null;
    }
}
