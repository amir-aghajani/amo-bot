<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use App\Core\Database\ChangeFeed;
use App\Core\Database\Transitions;
use App\Core\Exceptions\KeyReusedException;
use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Modules\Auth\Actor;
use App\Modules\Notifications\Enums\NoticeSubject;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Exceptions\StorageFullException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerPictures;
use App\Support\Input;
use App\Support\Persian;
use App\Support\Validation;
use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Support conversations («تیکت»): one service for every channel — the customer's website or bot, support's panel, the
 * website's admin API, the report group — so they all keep the same rules. A customer opens a ticket with its first
 * message (open: waiting on support), writes again (a closed ticket opens again), closes it, and rates it once closed;
 * support answers (answered; the customer is notified on every channel), closes it (notified) and reopens it.
 *
 * Concurrency: every status change is one conditional update (Transitions), so of two at once one wins and only the
 * winner tells anyone; a message and the status change it causes are written in one transaction under the ticket's row
 * lock (lock()), so a close arriving at the same moment lands before or after it, never between. Reopening drops the
 * rating: it was the customer's word on a conversation that was over.
 *
 * The report group hears every change except what was written in it (ShopReports), inside the change's transaction, so
 * a report the database refuses takes the change back with it; a rating only when first given or changed.
 *
 * Limits: a customer opens OPENS tickets per OPEN_WINDOW and writes MESSAGES messages (ratings included) per
 * MESSAGE_WINDOW; their uploads count in the shop's one picture budget (CustomerPictures::budget(), with receipts) —
 * each a 429 with the wait. A ticket holds MESSAGES_MAX messages and PICTURES_MAX uploaded pictures from the customer.
 */
final class Tickets
{
    public const SUBJECT_MIN = 3;
    public const SUBJECT_MAX = 120;
    public const BODY_MAX = 4000;
    public const RATING_NOTE_MAX = 500;

    /** The messages a ticket holds, the customer's and support's together; then a new ticket is the way on. */
    public const MESSAGES_MAX = 200;

    /**
     * The pictures a customer may upload to one ticket — each a file kept on the host while the ticket is open. Pictures
     * sent in the bot stay on Telegram and do not count; support is not limited.
     */
    public const PICTURES_MAX = 20;

    /** The refusal past PICTURES_MAX, under `file` (%s: the limit in Persian digits). */
    public const PICTURES_FULL = 'این تیکت بیشتر از %s تصویر از شما نمی‌گیرد؛ پیام را بدون تصویر بفرستید، یا تیکت تازه‌ای باز کنید.';

    /** Tickets a customer opens in a window of OPEN_WINDOW seconds. */
    public const OPENS = 10;
    public const OPEN_WINDOW = 3600;

    /** Messages a customer writes — a ticket's first among them, and their ratings — in a window of MESSAGE_WINDOW seconds. */
    public const MESSAGES = 30;
    public const MESSAGE_WINDOW = 600;

    /** Refusals by the state the ticket is in. */
    public const CLOSED = 'این تیکت بسته شده است.';
    public const NOT_CLOSED = 'این تیکت بسته نیست.';
    public const RATE_WHEN_CLOSED = 'امتیاز را پس از بسته شدن تیکت می‌توانید ثبت کنید.';
    public const FULL = 'این تیکت به سقف پیام‌ها رسیده؛ تیکت تازه‌ای باز کنید.';

    private const SUBJECT_MISSING = 'موضوع تیکت را بنویسید.';
    private const SUBJECT_SHORT = 'موضوع حداقل ' . self::SUBJECT_MIN . ' کاراکتر است.';
    private const BODY_MISSING = 'متن پیام را بنویسید.';
    private const NO_SUCH_SERVICE = 'این سرویس پیدا نشد.';

    /** What reopening a ticket clears: its end, and the rating the customer gave it then. */
    private const REOPENED = ['closed_at' => null, 'rating' => null, 'rating_note' => null];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly RateLimiter $limiter,
        private readonly TicketAttachments $attachments,
        private readonly ShopReports $reports,
        private readonly CustomerNotifier $notifier,
        private readonly ChangeFeed $feed,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The customer opens a ticket: its `subject`, the first message's `body`, the service it is about
     * (`subscription_id`, one of their own) and a picture, every refusal at once. The report group hears it.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `subject`, `body`, `subscription_id` or `file`
     * @throws TooManyAttemptsException 429: they opened OPENS in the window, wrote MESSAGES, or uploaded their pictures
     * @throws StorageFullException 503: an upload the host's disk has no room for
     */
    public function open(User $customer, array $input, UploadedFileInterface|Attachment|null $picture, TicketChannel $channel): Ticket
    {
        $errors = [];
        $subject = self::subject($input, $errors);
        $body = self::body($input, $errors);
        $service = self::service($customer, $input, $errors);
        $attachment = $this->attachment($picture, $errors);
        ValidationException::ifAny($errors);
        $this->throttle($customer->id, opening: true, uploader: $attachment?->bytes !== null ? $customer : null);

        return $this->writing(function (?string &$kept) use ($customer, $subject, $service, $body, $attachment, $channel): Ticket {
            $ticket = Ticket::query()->create([
                'user_id' => $customer->id,
                'subject' => $subject,
                'subscription_id' => $service?->id,
                'last_message_at' => now(),
            ]);
            $ticket->setRelation('user', $customer)->setRelation('subscription', $service);
            $message = $this->message($ticket, TicketAuthor::Customer, null, $body, $attachment, $channel, $kept);
            $this->reports->ticketOpened($ticket, $message);

            return $ticket;
        });
    }

    /**
     * The customer writes in their ticket: a closed one opens again, an answered one waits on support again. The report
     * group hears it under the ticket.
     *
     * @param array<string, mixed> $input `body`
     * @throws ValidationException 422 on `body` or `file` — an upload past PICTURES_MAX too —, or on `status`: the ticket holds MESSAGES_MAX
     * @throws TooManyAttemptsException 429: they wrote MESSAGES in the window, or uploaded their pictures
     * @throws StorageFullException 503: an upload the host's disk has no room for
     */
    public function write(Ticket $ticket, array $input, UploadedFileInterface|Attachment|null $picture, TicketChannel $channel): Ticket
    {
        $errors = [];
        $body = self::body($input, $errors);
        $attachment = $this->attachment($picture, $errors);
        $upload = $attachment?->bytes !== null;
        if ($upload && self::picturesFull($ticket)) {
            $errors['file'][] = self::picturesRefusal();
        }
        ValidationException::ifAny($errors);
        // Refused before it costs them a message of their window; checked again under the lock.
        self::assertNotFull($ticket);
        $this->throttle($ticket->user_id, opening: false, uploader: $upload ? $ticket->user : null);

        return $this->writing(function (?string &$kept) use ($ticket, $body, $attachment, $upload, $channel): Ticket {
            $this->lock($ticket);
            self::assertNotFull($ticket);
            if ($upload && self::picturesFull($ticket)) {
                throw ValidationException::on('file', self::picturesRefusal());
            }
            $message = $this->message($ticket, TicketAuthor::Customer, null, $body, $attachment, $channel, $kept);
            $reopened = $ticket->status === TicketStatus::Closed;
            $written = ['last_message_at' => now()];
            match ($ticket->status) {
                TicketStatus::Closed => Transitions::move($ticket, 'status', [TicketStatus::Closed], TicketStatus::Open, $written + self::REOPENED),
                TicketStatus::Answered => Transitions::move($ticket, 'status', [TicketStatus::Answered], TicketStatus::Open, $written),
                // Waiting on support already: the latest word is this one.
                TicketStatus::Open => $ticket->forceFill($written)->save(),
            };
            $this->reports->ticketMessage($ticket, $message, $reopened);

            return $ticket;
        });
    }

    /**
     * Support answers — from a panel, the website's admin API or the report group; the message's channel follows the
     * actor (TicketChannel::of()). The ticket becomes answered and unread by the customer (a closed one opens again so).
     * The customer is notified on every channel; the report group hears an answer written elsewhere. Under the request's
     * Idempotency-Key `$key` (a panel's or the website's admin API's, when it sends one), the same answer again — the
     * same words, the same picture by its name — finds the one the key wrote: nothing written, nobody told again.
     *
     * @param array<string, mixed> $input `body`
     * @throws ValidationException 422 on `body` or `file`, or on `status`: the ticket holds MESSAGES_MAX
     * @throws KeyReusedException 422 on `idempotency_key`: the key wrote another answer
     * @throws StorageFullException 503: an upload the host's disk has no room for
     */
    public function answer(Ticket $ticket, Actor $actor, array $input, UploadedFileInterface|Attachment|null $picture, ?string $key = null): Ticket
    {
        $errors = [];
        $body = self::body($input, $errors);
        $attachment = $this->attachment($picture, $errors);
        ValidationException::ifAny($errors);
        $channel = TicketChannel::of($actor);

        $message = null;
        $this->writing(function (?string &$kept) use ($ticket, $body, $attachment, $actor, $channel, $key, &$message): Ticket {
            $this->lock($ticket);
            if ($key !== null && self::answeredBy($ticket, $key, $body, $attachment)) {
                return $ticket;
            }
            self::assertNotFull($ticket);
            $message = $this->message($ticket, TicketAuthor::Support, $actor->name(), $body, $attachment, $channel, $kept, $key);
            $answered = ['last_message_at' => now(), 'customer_unread' => true];
            if ($ticket->status === TicketStatus::Answered) {
                $ticket->forceFill($answered)->save();
            } else {
                Transitions::move($ticket, 'status', [$ticket->status], TicketStatus::Answered, $answered + self::REOPENED);
            }
            if ($channel !== TicketChannel::Group) {
                $this->reports->ticketMessage($ticket, $message, false);
            }

            return $ticket;
        });
        if ($message === null) {
            return $ticket;
        }
        $this->logger->info('Ticket {id} answered by {reviewer} ({channel})', ['id' => $ticket->id, 'reviewer' => $actor->reviewer, 'channel' => $channel->value]);
        $this->notifier->ticketAnswered($ticket, $message);

        return $ticket;
    }

    /**
     * Close the ticket — by the customer (no actor), or by support, which tells the customer. The report group hears it,
     * and the ticket's last report loses its button.
     *
     * @throws ValidationException 422 on `status`: closed already
     */
    public function close(Ticket $ticket, ?Actor $actor): Ticket
    {
        $this->db->transaction(function () use ($ticket, $actor): void {
            if (!Transitions::move($ticket, 'status', [TicketStatus::Open, TicketStatus::Answered], TicketStatus::Closed, ['closed_at' => now()])) {
                throw ValidationException::on('status', self::CLOSED);
            }
            $this->reports->ticketClosed($ticket, $actor === null);
        });
        if ($actor !== null) {
            $this->logger->info('Ticket {id} closed by {reviewer}', ['id' => $ticket->id, 'reviewer' => $actor->reviewer]);
            $this->notifier->ticketClosed($ticket);
        }

        return $ticket;
    }

    /**
     * Support reopens a closed ticket: waiting on support again, its rating dropped. The report group hears it.
     *
     * @throws ValidationException 422 on `status`: it is not closed
     */
    public function reopen(Ticket $ticket, Actor $actor): Ticket
    {
        $this->db->transaction(function () use ($ticket): void {
            if (!Transitions::move($ticket, 'status', [TicketStatus::Closed], TicketStatus::Open, self::REOPENED)) {
                throw ValidationException::on('status', self::NOT_CLOSED);
            }
            $this->reports->ticketReopened($ticket);
        });
        $this->logger->info('Ticket {id} reopened by {reviewer}', ['id' => $ticket->id, 'reviewer' => $actor->reviewer]);

        return $ticket;
    }

    /**
     * The customer rates their closed ticket — `rating` 1 to 5 and a `note` — replacing any earlier rating; each counts
     * as a message of their window. The report group hears a first or changed rating; the same one again changes nothing.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException 422 on `rating` or `note`, then on `status` while it is not closed
     * @throws TooManyAttemptsException 429: they wrote MESSAGES in the window
     */
    public function rate(Ticket $ticket, array $input): Ticket
    {
        $errors = [];
        $rating = Validation::rating($input['rating'] ?? null);
        if ($rating === null) {
            $errors['rating'] = [Validation::RATING_RANGE];
        }
        $note = null;
        try {
            $note = Input::note($input, 'note', self::RATING_NOTE_MAX);
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        ValidationException::ifAny($errors);
        $this->throttle($ticket->user_id, opening: false);

        $this->db->transaction(function () use ($ticket, $rating, $note): void {
            $this->lock($ticket);
            if ($ticket->status !== TicketStatus::Closed) {
                throw ValidationException::on('status', self::RATE_WHEN_CLOSED);
            }
            if ($ticket->rating === $rating && $ticket->rating_note === $note) {
                return;
            }
            $ticket->forceFill(['rating' => $rating, 'rating_note' => $note])->save();
            $this->reports->ticketRated($ticket);
        });

        return $ticket;
    }

    /**
     * The customer read their ticket (the bot's screen showed it, or their website says so): support's latest words are
     * read, and so are the ticket's notices in their website's feed. Written quietly: no panel screen shows either.
     */
    public function markRead(Ticket $ticket): void
    {
        if ($ticket->customer_unread) {
            $this->feed->quietly(static fn() => Ticket::query()->whereKey($ticket->id)->where('customer_unread', true)->update(['customer_unread' => false]));
            $ticket->forceFill(['customer_unread' => false])->syncOriginalAttribute('customer_unread');
        }
        Notification::query()->where('user_id', $ticket->user_id)->whereNull('read_at')
            ->where('subject_type', NoticeSubject::Ticket->value)->where('subject_id', $ticket->id)
            ->update(['read_at' => now()]);
    }

    /**
     * Write a ticket's message and keep its picture in one transaction; a picture kept for a message that was then not
     * written is deleted again.
     *
     * @param \Closure(?string &$kept): Ticket $work `$kept` is set to the name of the picture kept, once there is one
     */
    private function writing(\Closure $work): Ticket
    {
        $kept = null;
        try {
            return $this->db->transaction(static function () use ($work, &$kept): Ticket {
                return $work($kept);
            });
        } catch (\Throwable $e) {
            if ($kept !== null) {
                $this->attachments->discard($kept);
            }

            throw $e;
        }
    }

    /**
     * Lock the ticket's row until the transaction ends (a close, an answer or another message at the same moment waits
     * for it) and read it again onto `$ticket` as it stands under the lock.
     */
    private function lock(Ticket $ticket): void
    {
        $held = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
        $ticket->setRawAttributes($held->getAttributes(), sync: true);
    }

    /**
     * Whether the ticket holds the answer the request's key wrote (read under the ticket's lock): the same words and the
     * same picture (by its name, or none) — the request again.
     *
     * @throws KeyReusedException 422 on `idempotency_key`: the key wrote another answer
     */
    private static function answeredBy(Ticket $ticket, string $key, string $body, ?Attachment $attachment): bool
    {
        $earlier = TicketMessage::query()->where('ticket_id', $ticket->id)->where('request_key', $key)->lockForUpdate()->first();
        if ($earlier === null) {
            return false;
        }

        return $earlier->body === $body && $earlier->attachment_name === $attachment?->name ? true : throw new KeyReusedException();
    }

    /** @throws ValidationException 422 on `status`: the ticket holds MESSAGES_MAX messages */
    private static function assertNotFull(Ticket $ticket): void
    {
        if (TicketMessage::query()->where('ticket_id', $ticket->id)->count() >= self::MESSAGES_MAX) {
            throw ValidationException::on('status', self::FULL);
        }
    }

    /** Whether the ticket keeps PICTURES_MAX pictures the customer uploaded. */
    private static function picturesFull(Ticket $ticket): bool
    {
        return TicketMessage::query()->where('ticket_id', $ticket->id)->where('author', TicketAuthor::Customer->value)
            ->whereNotNull('attachment_path')->count() >= self::PICTURES_MAX;
    }

    private static function picturesRefusal(): string
    {
        return sprintf(self::PICTURES_FULL, Persian::digits((string) self::PICTURES_MAX));
    }

    private function message(Ticket $ticket, TicketAuthor $author, ?string $reviewer, string $body, ?Attachment $attachment, TicketChannel $channel, ?string &$kept, ?string $key = null): TicketMessage
    {
        $picture = $this->attachments->keep($ticket, $attachment);
        $kept = $picture['attachment_path'] ?? null;

        return TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'author' => $author,
            'reviewer' => $reviewer,
            'body' => $body,
            'channel' => $channel,
            'request_key' => $key,
        ] + $picture);
    }

    /**
     * A message's picture: an upload is judged (its refusal under `file`), a Telegram one taken as it is.
     *
     * @param array<string, list<string>> $errors
     * @throws StorageFullException 503: an upload the host's disk has no room for
     */
    private function attachment(UploadedFileInterface|Attachment|null $picture, array &$errors): ?Attachment
    {
        if (!$picture instanceof UploadedFileInterface) {
            return $picture;
        }
        try {
            return $this->attachments->judge($picture);
        } catch (ValidationException $e) {
            $errors += $e->errors();

            return null;
        }
    }

    /**
     * Count the customer's try against their windows: opening a ticket counts once (and its first message as a message),
     * every message and rating once, and an upload (`$uploader` set) in the picture budget shared with their receipts.
     * The specific windows are checked before anything is counted, so a refused try costs nothing.
     *
     * @throws TooManyAttemptsException
     */
    private function throttle(int $customer, bool $opening, ?User $uploader = null): void
    {
        $opened = ['tickets|open|' . $customer, self::OPENS, self::OPEN_WINDOW];
        $wait = $opening ? $this->limiter->availableIn($opened[0], self::OPENS) : 0;
        if ($wait > 0) {
            throw TooManyAttemptsException::wait('تیکت زیادی باز کرده‌اید', $wait);
        }
        $pictures = $uploader !== null ? CustomerPictures::budget($uploader) : null;
        $wait = $pictures !== null ? $this->limiter->availableIn($pictures[0], $pictures[1]) : 0;
        if ($wait > 0) {
            throw TooManyAttemptsException::wait(CustomerPictures::TOO_MANY, $wait);
        }

        $messages = ['tickets|message|' . $customer, self::MESSAGES, self::MESSAGE_WINDOW];
        $this->limiter->enforce(array_values(array_filter([$opening ? $opened : null, $messages, $pictures])), 'پیام زیادی فرستاده‌اید');
    }

    /**
     * A ticket's subject: SUBJECT_MIN to SUBJECT_MAX characters.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function subject(array $input, array &$errors): string
    {
        $subject = Input::text($input, 'subject');
        $length = mb_strlen($subject);
        $problem = match (true) {
            $length === 0 => self::SUBJECT_MISSING,
            $length < self::SUBJECT_MIN => self::SUBJECT_SHORT,
            $length > self::SUBJECT_MAX => Validation::tooLong('موضوع', self::SUBJECT_MAX),
            default => null,
        };
        if ($problem !== null) {
            $errors['subject'] = [$problem];
        }

        return $subject;
    }

    /**
     * A message's words: trimmed, its line breaks kept, 1 to BODY_MAX characters.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function body(array $input, array &$errors): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", Input::text($input, 'body'));
        $problem = match (true) {
            $body === '' => self::BODY_MISSING,
            mb_strlen($body) > self::BODY_MAX => Validation::tooLong('پیام', self::BODY_MAX),
            default => null,
        };
        if ($problem !== null) {
            $errors['body'] = [$problem];
        }

        return $body;
    }

    /**
     * The service a ticket is about: one of the customer's own, or none when none is sent.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    private static function service(User $customer, array $input, array &$errors): ?Subscription
    {
        $sent = $input['subscription_id'] ?? null;
        if ($sent === null || $sent === '') {
            return null;
        }
        $id = Input::integerOf($sent);
        $service = $id === null ? null : Subscription::query()->where('user_id', $customer->id)->find($id);
        if ($service === null) {
            $errors['subscription_id'] = [self::NO_SUCH_SERVICE];
        }

        return $service;
    }
}
