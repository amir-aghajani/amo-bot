<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Models;

use App\Modules\Bots\Models\Concerns\BelongsToBot;
use App\Modules\Telegram\Reports\Topic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One report queued for the admins' report group (see Reports\ShopReports, sent by Reports\ReportSender).
 *
 * @property int $id
 * @property Topic $topic The topic it goes to
 * @property int $priority Where it stands in the queue, its topic's (Topic::priority()): the lowest goes first
 * @property string $text HTML; the caption when a message is copied
 * @property int|null $copy_chat_id A customer's chat holding the message to copy (a receipt)
 * @property int|null $copy_message_id That message
 * @property string|null $photo_path A picture of the shop's own — a receipt, a ticket's picture, uploaded from a website or a panel — sent as a photo with `text` as its caption: where it is kept (Users\Services\CustomerPictures::reference())
 * @property list<list<array<string, mixed>>>|null $keyboard The rows of inline buttons it carries (a receipt's «تایید» / «رد»)
 * @property string|null $ref What it is about ("receipt:12", "ticket:5"), for a later report to reply to
 * @property int|null $ticket_id The support ticket it is about: what a bot admin's reply to it answers (Reports\TicketReplies), and kept while that ticket is not closed (ReportSender::pruneTickets())
 * @property string|null $reply_ref The ref of the report this one replies to
 * @property bool $clears_buttons Once sent, the report it replies to loses its buttons (a receipt's verdict)
 * @property int $attempts Senders that went quiet holding it (a crash mid-send)
 * @property string|null $lease_token The sender holding it (Core\Database\Lease)
 * @property Carbon|null $leased_until
 * @property int|null $chat_id The group it went to
 * @property int|null $message_id Its message there
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at Given up on: Telegram refused it for good, or it waited too long
 * @property string|null $error Why it was given up on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ReportMessage extends Model
{
    use BelongsToBot;

    protected $table = 'report_messages';

    protected $fillable = ['topic', 'priority', 'text', 'copy_chat_id', 'copy_message_id', 'photo_path', 'keyboard', 'ref', 'ticket_id', 'reply_ref', 'clears_buttons'];

    /** @var array<string, string> */
    protected $casts = [
        'topic' => Topic::class,
        'priority' => 'integer',
        'copy_chat_id' => 'integer',
        'copy_message_id' => 'integer',
        'ticket_id' => 'integer',
        'keyboard' => 'array',
        'clears_buttons' => 'boolean',
        'attempts' => 'integer',
        'leased_until' => 'datetime',
        'chat_id' => 'integer',
        'message_id' => 'integer',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /** @return Builder<static> Neither sent nor given up on. */
    public static function waiting(): Builder
    {
        return static::query()->whereNull('sent_at')->whereNull('failed_at');
    }

    /**
     * A message the bot wrote in the group itself, not through the queue — a receipt's prompt for a reason —, recorded as
     * sent there: what `ref` says it is about is how a reply to it is told apart, by the message it answers, never by its
     * words.
     */
    public static function posted(Topic $topic, string $text, string $ref, int $chatId, int $messageId): void
    {
        static::query()->forceCreate(['topic' => $topic, 'text' => $text, 'ref' => $ref, 'chat_id' => $chatId, 'message_id' => $messageId, 'sent_at' => now()]);
    }
}
