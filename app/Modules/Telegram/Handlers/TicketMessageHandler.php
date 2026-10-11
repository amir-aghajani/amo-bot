<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Models\TelegramSession;
use App\Modules\Telegram\Session\ChatSession;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerPictures;
use App\Support\Text;

/**
 * What the customer writes in a support ticket, while the chat is at `ticket.new` (a new ticket's first message) or
 * `ticket.reply.{id}` (the ticket's next). A message is words — a text or a picture's caption — and at most one picture
 * (a photo, or a picture sent as a file). The shop takes no picture without words, so a picture alone, or one whose words
 * were refused, is kept in the session for the next message. The rules are Support\Services\Tickets'.
 *
 * Once a message is taken the chat stays on that ticket, like a chat, until the customer goes elsewhere, REPLY_SECONDS
 * pass, or a notice of another of their tickets arrives (leaveOtherTicket()) — so a later message never lands in the
 * wrong ticket.
 */
final class TicketMessageHandler implements Handler
{
    /** The state's prefix (routes/bot.php): `ticket.new`, `ticket.reply.{id}`. */
    public const STATE = 'ticket';

    /** How long the chat stays on a ticket without a message: then what the customer sends is not the ticket's. */
    public const REPLY_SECONDS = 900;

    private const NEW = 'ticket.new';
    private const REPLY = 'ticket.reply.';

    public function __construct(
        private readonly Tickets $tickets,
        private readonly CustomerPictures $pictures,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
        private readonly FallbackHandler $fallback,
    ) {}

    /** A new ticket's first message awaited — about the customer's service `$serviceId`, or none. */
    public static function awaitNew(ChatSession $session, ?int $serviceId): void
    {
        $session->enter(self::NEW, ['service' => $serviceId]);
    }

    /** Await the ticket's next message for REPLY_SECONDS; `$from` is the page of «تیکت‌های من» its screen goes back to. */
    public static function awaitReply(ChatSession $session, Ticket $ticket, int $from): void
    {
        $session->enter(self::REPLY . $ticket->id, ['from' => $from, 'at' => now()->getTimestamp()]);
    }

    /**
     * A notice of `$ticket` reached the customer's chat: a chat waiting on another of their tickets stops waiting, so
     * what they type next does not land there. One conditional update, leaving any other step the chat is at alone.
     */
    public static function leaveOtherTicket(int $chatId, Ticket $ticket): void
    {
        TelegramSession::query()->where('chat_id', $chatId)
            ->where('state', 'like', self::REPLY . '%')
            ->where('state', '!=', self::REPLY . $ticket->id)
            ->update(['state' => null]);
    }

    /**
     * A new ticket's subject: the message's first non-empty line, cut to Tickets::SUBJECT_MAX at a word. A first line
     * shorter than SUBJECT_MIN (a «hi») takes the next words with it; null when the whole message is shorter than that.
     */
    private static function subjectOf(string $words): ?string
    {
        $lines = array_values(array_filter(
            array_map(static fn(string $line): string => trim((string) preg_replace('/\s+/u', ' ', $line)), explode("\n", $words)),
            static fn(string $line): bool => $line !== '',
        ));
        $subject = $lines[0] ?? '';
        if (mb_strlen($subject) < Tickets::SUBJECT_MIN) {
            $subject = implode(' ', $lines);
        }

        return mb_strlen($subject) < Tickets::SUBJECT_MIN ? null : Text::fit($subject, Tickets::SUBJECT_MAX, atWord: true);
    }

    public function handle(Context $ctx): void
    {
        $state = (string) $ctx->session->state();
        $replying = str_starts_with($state, self::REPLY);
        if ($replying && !self::lately($ctx->session)) {
            // The chat stopped on the ticket a while ago: this message is not the ticket's.
            $ctx->session->clear();
            $this->fallback->handle($ctx);

            return;
        }
        if ($replying) {
            $ctx->session->put('at', now()->getTimestamp());
        }

        $sent = $this->sent($ctx);
        if ($sent === null) {
            $ctx->reply($this->texts->get(BotText::TicketTextOnly));

            return;
        }
        [$words, $picture] = $sent;

        if ($words === '') {
            $this->keep($ctx, $picture);

            return;
        }

        $picture ??= $this->kept($ctx);
        if ($replying) {
            $this->write($ctx, (int) substr($state, strlen(self::REPLY)), $words, $picture);
        } else {
            $this->open($ctx, $words, $picture);
        }
    }

    /** The first message opens the ticket (about the service picked, if any); the chat stays on it from now. */
    private function open(Context $ctx, string $words, ?Attachment $picture): void
    {
        $subject = self::subjectOf($words);
        if ($subject === null) {
            $this->refuse($ctx, BotText::TicketTooShort, [], $picture);

            return;
        }

        $service = $ctx->session->get('service');
        try {
            $ticket = $this->opened($ctx->user, ['subject' => $subject, 'body' => $words], is_int($service) ? $service : null, $picture);
        } catch (ValidationException | TooManyAttemptsException $e) {
            $this->refuse($ctx, BotText::TicketRefused, ['reason' => self::reason($e)], $picture);

            return;
        }

        self::awaitReply($ctx->session, $ticket, 0);
        $ctx->reply($this->texts->render(BotText::TicketOpened, ['ticket' => $ticket->id, 'subject' => $ticket->subject]), ['reply_markup' => $this->view($ticket, 0)]);
    }

    /**
     * The ticket opened about the service — or about none when that service was deleted since it was picked: the
     * customer's words are not lost over it.
     *
     * @param array<string, string> $input
     * @throws ValidationException
     * @throws TooManyAttemptsException
     */
    private function opened(User $customer, array $input, ?int $service, ?Attachment $picture): Ticket
    {
        if ($service === null) {
            return $this->tickets->open($customer, $input, $picture, TicketChannel::Bot);
        }

        try {
            return $this->tickets->open($customer, $input + ['subscription_id' => $service], $picture, TicketChannel::Bot);
        } catch (ValidationException $e) {
            if (array_keys($e->errors()) !== ['subscription_id']) {
                throw $e;
            }

            return $this->tickets->open($customer, $input, $picture, TicketChannel::Bot);
        }
    }

    /** A later message written in the ticket (a closed one opens again); the chat stays on it. */
    private function write(Context $ctx, int $ticketId, string $words, ?Attachment $picture): void
    {
        $ticket = Ticket::of($ctx->user)->find($ticketId);
        if ($ticket === null) {
            $ctx->session->clear();
            $ctx->reply($this->texts->get(BotText::TicketNotFound), ['reply_markup' => $this->buttons->backOnly(TicketHandler::listCallback(1))]);

            return;
        }

        $from = $ctx->session->get('from');
        $from = is_int($from) ? $from : 0;
        try {
            $this->tickets->write($ticket, ['body' => $words], $picture, TicketChannel::Bot);
        } catch (ValidationException | TooManyAttemptsException $e) {
            $this->refuse($ctx, BotText::TicketRefused, ['reason' => self::reason($e)], $picture);

            return;
        }

        self::awaitReply($ctx->session, $ticket, $from);
        $ctx->reply($this->texts->render(BotText::TicketMessageSent, ['ticket' => $ticket->id]), ['reply_markup' => $this->view($ticket, $from)]);
    }

    /**
     * What the customer sent, as a ticket's message: its text (a message, or a picture's caption) and its picture; null for
     * anything else (a voice, a sticker, a file that is no picture).
     *
     * @return array{string, Attachment|null}|null
     */
    private function sent(Context $ctx): ?array
    {
        $update = $ctx->update;
        if ($update->contentKind() === 'text') {
            return [trim((string) $update->text()), null];
        }

        $picture = $update->picture($this->pictures->isPicture(...));

        return $picture === null ? null : [trim((string) $update->caption()), Attachment::telegram($picture['file_id'], CustomerPictures::cleanName($picture['name']))];
    }

    /**
     * A picture without words: kept for the next text, which is asked for. Telegram sends an album a picture at a time;
     * the album's later pictures are not asked about again — a message takes one picture, the first.
     */
    private function keep(Context $ctx, ?Attachment $picture): void
    {
        if ($picture === null) {
            $ctx->reply($this->texts->get(BotText::TicketTextOnly));

            return;
        }

        $album = $this->album($ctx);
        if ($album !== null && $album === $ctx->session->get('album') && $this->kept($ctx) !== null) {
            return;
        }

        $this->hold($ctx, $picture);
        $ctx->reply($this->texts->get(BotText::TicketPictureNeedsWords));
    }

    /** The picture kept for the words still to come, if one is. */
    private function kept(Context $ctx): ?Attachment
    {
        $fileId = $ctx->session->get('picture');
        $name = $ctx->session->get('picture_name');

        return is_string($fileId) && $fileId !== '' ? Attachment::telegram($fileId, is_string($name) ? $name : null) : null;
    }

    /** Hold the picture in the chat's scratch for the next message's words, with the album it came in. */
    private function hold(Context $ctx, Attachment $picture): void
    {
        $ctx->session->put('picture', $picture->fileId);
        $ctx->session->put('picture_name', $picture->name);
        $ctx->session->put('album', $this->album($ctx));
    }

    /** The album (Telegram's media group) the message came in, if any. */
    private function album(Context $ctx): ?string
    {
        $album = ($ctx->update->message() ?? [])['media_group_id'] ?? null;

        return is_scalar($album) ? (string) $album : null;
    }

    /**
     * The message refused (too short to open a ticket with, or the shop's refusal), the next one still awaited; a
     * picture that came with it is held for the next one, which the refusal says.
     *
     * @param array<string, scalar|Html> $values
     */
    private function refuse(Context $ctx, BotText $text, array $values, ?Attachment $picture): void
    {
        if ($picture !== null) {
            $this->hold($ctx, $picture);
        }
        $ctx->reply($this->texts->render($text, $values + ['kept' => $picture !== null ? $this->texts->part(BotText::TicketPictureKept) : '']));
    }

    /** The refusal's first message (words too long, the ticket full, too many messages a while). */
    private static function reason(ValidationException|TooManyAttemptsException $e): string
    {
        return $e instanceof ValidationException ? (array_values($e->errors())[0][0] ?? $e->getMessage()) : $e->getMessage();
    }

    /** Whether the chat came to its ticket, or wrote in it, within the last REPLY_SECONDS. */
    private static function lately(ChatSession $session): bool
    {
        $at = $session->get('at');

        return is_int($at) && now()->getTimestamp() - $at <= self::REPLY_SECONDS;
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>} «🗂️ مشاهده تیکت»: the ticket's screen. */
    private function view(Ticket $ticket, int $from): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::TicketView), TicketHandler::screenCallback($ticket->id, $from)))->build();
    }
}
