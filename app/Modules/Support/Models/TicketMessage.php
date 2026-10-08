<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One message of a ticket: the customer's or support's words, and at most one picture — sent in Telegram (the bot, the
 * report group: its file id, Telegram keeps it) or uploaded (a website, a panel: kept in the tickets' folder, Users\
 * Enums\PictureFolder::Tickets, until the ticket has been closed a while — TicketAttachments::prune(), which leaves its
 * name: the message still says it had one) —, where it was written, and who of support wrote it.
 *
 * @property int $id
 * @property int $ticket_id
 * @property TicketAuthor $author
 * @property string|null $reviewer Who of support wrote it — a panel's principal, a bot admin's @username or tg:<id>; null for the customer's
 * @property string $body
 * @property string|null $attachment_file_id Its picture, sent in Telegram: the file id
 * @property string|null $attachment_path Its picture, uploaded: its name in the tickets' folder; null once it is no longer kept
 * @property string|null $attachment_name Its picture's name, as its sender's device gave it or one made up; null without a picture
 * @property TicketChannel $channel
 * @property Carbon $created_at
 */
class TicketMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ticket_messages';

    protected $fillable = ['ticket_id', 'author', 'reviewer', 'body', 'attachment_file_id', 'attachment_path', 'attachment_name', 'channel'];

    /** @var array<string, string> */
    protected $casts = [
        'ticket_id' => 'integer',
        'author' => TicketAuthor::class,
        'channel' => TicketChannel::class,
    ];

    /** It came with a picture — kept still, or not any more (its name stays). */
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
