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
 * A ticket message's picture, under the shop's one picture rule (Users\Services\CustomerPictures): an upload judged by
 * its bytes and kept in the tickets' folder (`tickets.path`) under a name the shop makes up until its ticket has been
 * closed KEEP_DAYS (prune()), or a photo sent in Telegram, kept by its file id. Served to the panels and the website
 * (fetch()), and sent to a customer's chat as a photo (send(): support's answer, the bot's ticket screen).
 */
final class TicketAttachments
{
    /** Days an uploaded picture of a closed ticket is kept after it closed; a reopened ticket keeps its own. */
    public const KEEP_DAYS = 30;

    /** What a refusal of a message's picture calls it. */
    private const WHAT = 'تصویر';

    public function __construct(
        private readonly CustomerPictures $pictures,
        private readonly BotApi $api,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * An upload judged as a message's picture (CustomerPictures::judge()); null for no file.
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
     * Keep the picture and return the message's columns for it: an upload's name in the tickets' folder, or a Telegram
     * file id, and the picture's name; nothing without one.
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

    /** Delete an uploaded picture nobody needs: its message was not written after all. */
    public function discard(string $path): void
    {
        $this->pictures->discard(PictureFolder::Tickets, $path);
    }

    /**
     * The message's picture: its bytes, their type as the bytes say, and its name.
     *
     * @return array{body: string, mime: string, name: string}
     * @throws AttachmentUnavailableException 404: none, or its file is gone (from Telegram, or no longer kept here)
     * @throws TelegramUnreachableException 502: Telegram could not be asked; the picture is still there
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
     * Send the message's picture to a chat as a photo, with `$options` (caption, buttons): Telegram's by its file id, an
     * upload by its bytes. With `$fetch`, a file id Telegram will not send as a photo (a picture sent as a file) is
     * fetched and sent by its bytes. True once it went; false when it could not (none kept, file gone, Telegram turned
     * the picture down). The chat's own trouble (blocked, gone) and Telegram's (out of reach) go up like any message's.
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
     * Delete the uploaded pictures of the shop's tickets closed KEEP_DAYS ago. Each message is cleared first by a
     * conditional update that still requires its ticket closed, so a ticket reopened meanwhile keeps its pictures; the
     * message keeps the picture's name, so it still says it had one. Telegram's pictures stay Telegram's.
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
     * Rethrow a refusal that is not about the picture: the chat's trouble (blocked, gone) or Telegram's own (out of reach,
     * a flood limit), as for any message.
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
