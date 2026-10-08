<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Exceptions\AttachmentUnavailableException;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Api\Refusal;
use App\Modules\Telegram\Api\TelegramApiException;
use App\Modules\Telegram\Api\TelegramUnreachableException;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Exceptions\StorageFullException;
use App\Modules\Users\Services\CustomerPictures;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * A ticket message's picture, under the shop's one rule for a picture it is sent (Users\Services\CustomerPictures): an
 * upload judged by its bytes and kept in the tickets' folder (PictureFolder::Tickets, the container's `tickets.path`,
 * storage/uploads/tickets) under a name of the shop's own — until its ticket has been closed KEEP_DAYS (prune()): then it
 * goes, its message saying it had one —, or a photo sent in Telegram, kept by its file id; served on demand to the panels
 * and to the customer's website (fetch()), and sent to a customer's chat as a photo (send(): support's answer, the
 * bot's ticket screen).
 */
final class TicketAttachments
{
    /** Days an uploaded picture of a closed ticket is kept once it closed — a ticket opened again keeps its own. */
    public const KEEP_DAYS = 30;

    /** What a refusal of a message's picture calls it. */
    private const WHAT = 'تصویر';

    public function __construct(
        private readonly CustomerPictures $pictures,
        private readonly BotApi $api,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * An upload as a message's picture, judged (CustomerPictures::judge()); null for none — no file, or a form sent
     * without one.
     *
     * @throws ValidationException 422 on `file`
     * @throws StorageFullException 503: the host's disk has no room for it
     */
    public function judge(?UploadedFileInterface $file): ?Attachment
    {
        if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $picture = $this->pictures->judge($file, self::WHAT);

        return Attachment::upload($picture['bytes'], $picture['extension'], CustomerPictures::cleanName($file->getClientFilename()));
    }

    /**
     * What a message of the ticket records of its picture: an upload kept in the tickets' folder (its name there), a
     * Telegram photo's file id — and the picture's name; nothing without one.
     *
     * @return array<string, string>
     * @throws \RuntimeException when the folder cannot be written
     */
    public function keep(Ticket $ticket, ?Attachment $attachment): array
    {
        return match (true) {
            $attachment === null => [],
            $attachment->fileId !== null => ['attachment_file_id' => $attachment->fileId, 'attachment_name' => $attachment->name],
            default => [
                'attachment_path' => $this->pictures->keep(PictureFolder::Tickets, $ticket->bot_id . '-' . $ticket->id, (string) $attachment->bytes, (string) $attachment->extension),
                'attachment_name' => $attachment->name,
            ],
        };
    }

    /** An uploaded picture nobody needs any more: a message that was never written after all. */
    public function discard(string $path): void
    {
        $this->pictures->discard(PictureFolder::Tickets, $path);
    }

    /**
     * The message's picture: its bytes, their type as the bytes say, and its name.
     *
     * @return array{body: string, mime: string, name: string}
     * @throws AttachmentUnavailableException 404: none, or its file is no more — gone from Telegram, deleted, no longer
     *                                        kept (its ticket closed KEEP_DAYS)
     * @throws TelegramUnreachableException 502: Telegram could not be asked — the picture is still there
     */
    public function fetch(TicketMessage $message): array
    {
        $body = match (true) {
            $message->attachment_path !== null => $this->pictures->read(PictureFolder::Tickets, $message->attachment_path) ?? throw AttachmentUnavailableException::removed(),
            $message->attachment_file_id !== null => $this->pictures->fromTelegram($message->attachment_file_id) ?? throw AttachmentUnavailableException::gone(),
            $message->hasPicture() => throw AttachmentUnavailableException::removed(),
            default => throw AttachmentUnavailableException::none(),
        };

        return CustomerPictures::served($body, $message->attachment_name, 'picture-' . $message->id);
    }

    /**
     * The message's picture sent to a chat as a photo of its own, `$options` its caption and buttons — Telegram's by its
     * file id, an upload by its bytes; with `$fetch`, a file id Telegram will not send as a photo (a picture the customer
     * sent as a file) fetched and sent by its bytes. True once it went; false when it could not — none is kept, its file
     * is gone, Telegram turned the picture itself down. The chat's own trouble (blocked, gone) and Telegram's (out of
     * reach) go up, as any message's do.
     *
     * @param array<string, mixed> $options
     * @throws TelegramApiException
     * @throws TelegramUnreachableException with `$fetch`: Telegram could not hand the file over
     */
    public function send(int|string $chatId, TicketMessage $message, array $options, bool $fetch): bool
    {
        $bytes = null;
        if ($message->attachment_file_id !== null) {
            try {
                $this->api->sendPhotoById($chatId, $message->attachment_file_id, $options);

                return true;
            } catch (TelegramApiException $e) {
                self::throwUnlessThePicturesOwn($e);
            }
            $bytes = $fetch ? $this->pictures->fromTelegram($message->attachment_file_id) : null;
        } elseif ($message->attachment_path !== null) {
            $bytes = $this->pictures->read(PictureFolder::Tickets, $message->attachment_path);
        }
        if ($bytes === null) {
            return false;
        }

        try {
            $this->api->sendPhoto($chatId, $bytes, (string) ($message->attachment_name ?? 'picture.jpg'), $options);

            return true;
        } catch (TelegramApiException $e) {
            self::throwUnlessThePicturesOwn($e);

            return false;
        }
    }

    /**
     * The uploaded pictures of the shop's tickets closed KEEP_DAYS ago go — the file deleted once its message no longer
     * names it, each message's own name left, so it still says it had a picture (fetch(): no longer kept). One opened
     * again meanwhile keeps its own: the message is let go of only while its ticket is closed still. Telegram's pictures
     * stay Telegram's.
     */
    public function prune(): void
    {
        $closed = Ticket::query()->select('id')->where('status', TicketStatus::Closed->value)->where('closed_at', '<', now()->subDays(self::KEEP_DAYS)->utc());
        $pruned = 0;
        foreach (TicketMessage::query()->whereNotNull('attachment_path')->whereIn('ticket_id', $closed)->get(['id', 'attachment_path']) as $message) {
            $path = (string) $message->attachment_path;
            if (TicketMessage::query()->whereKey($message->id)->where('attachment_path', $path)->whereIn('ticket_id', clone $closed)->update(['attachment_path' => null]) === 1) {
                $this->pictures->discard(PictureFolder::Tickets, $path);
                $pruned++;
            }
        }
        if ($pruned > 0) {
            $this->logger->info('{count} uploaded pictures of tickets closed {days} days ago were deleted', ['count' => $pruned, 'days' => self::KEEP_DAYS]);
        }
    }

    /**
     * A picture Telegram turned down: the chat's trouble (the customer blocked the bot, their chat is gone) and Telegram's
     * own (out of reach, a flood limit) are any message's, and go up; anything else is the picture's.
     *
     * @throws TelegramApiException
     */
    private static function throwUnlessThePicturesOwn(TelegramApiException $e): void
    {
        if ($e->refusal()->isTransient() || $e->is(Refusal::Forbidden, Refusal::ChatGone, Refusal::TokenRejected)) {
            throw $e;
        }
    }
}
