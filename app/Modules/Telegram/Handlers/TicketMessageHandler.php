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
 * What the customer writes in a support ticket — the chat's state while a message is awaited, entered by TicketHandler's
 * prompts: `ticket.new` (a new ticket's first message, the service it is about in its scratch) or `ticket.reply.{id}`
 * (the ticket's next). A message is words — a text, or a picture's caption — and at most one picture: a Telegram photo,
 * or a picture sent as a file, judged by its bytes (CustomerPictures::isPicture(), the shop's one rule for a picture it is
 * sent), kept by Support as its Telegram file id. A picture without words is kept and its words asked for — the next
 * text goes with it, as the shop takes no picture without words (another picture of the same album is not asked about
 * again) —, and so is one whose words were refused (too short to open a ticket with, too long, too many messages a
 * while): kept for the next message, which the refusal says. Anything else (a voice, a video, a sticker, a file that is
 * no picture) is refused, the message still awaited.
 *
 * The first message opens the ticket (Tickets::open(), channel bot: the report group hears of it as of one opened on the
 * website), its subject the message's first line cut to fit (subjectOf()); every later one is written in it
 * (Tickets::write(): a closed ticket opens again). Each is answered with what came of it and «🗂️ مشاهده تیکت». Taken,
 * the chat stays on the ticket — what the customer sends next is added to it, as one does in a chat — until they go
 * elsewhere (a command, the menu, any button of the bot's screens leaves the state), for REPLY_SECONDS after their last
 * message: a message after that is not the ticket's — the fallback answers it, the state left —, and a notice of
 * another of their tickets ends it at once (leaveOtherTicket()); «✍️ پاسخ» on a ticket's screen or under a notice is
 * the way back in. A refusal — the words too long, too many tickets or messages in too little time, the ticket full — is
 * the service's own words, the message still awaited.
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

    /** The ticket's next message awaited — `$from` the page of «تیکت‌های من» its screen goes back to —, REPLY_SECONDS from now. */
    public static function awaitReply(ChatSession $session, Ticket $ticket, int $from): void
    {
        $session->enter(self::REPLY . $ticket->id, ['from' => $from, 'at' => now()->getTimestamp()]);
    }

    /**
     * A notice of `$ticket` reached the customer's chat: a chat left on another of their tickets leaves it — what they
     * type next is not that other ticket's (the notice's «✍️ پاسخ» takes them to this one). One conditional update: a
     * step the chat moved to meanwhile is the customer's own.
     */
    public static function leaveOtherTicket(int $chatId, Ticket $ticket): void
    {
        TelegramSession::query()->where('chat_id', $chatId)
            ->where('state', 'like', self::REPLY . '%')
            ->where('state', '!=', self::REPLY . $ticket->id)
            ->update(['state' => null]);
    }

    /**
     * A new ticket's subject, from its first message: the first line with words in it, cut to Tickets::SUBJECT_MAX — at a
     * space near the end when there is one — with «…». A first line shorter than SUBJECT_MIN (a «hi» on a line of its own)
     * takes the message's next words with it — the message on one line, cut the same way —; null for a message shorter
     * than that whole: it says too little to be a ticket.
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
            // The chat was left on the ticket a while ago: what it says now is not taken as the ticket's.
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

    /** The first message: the ticket opened with it — about the service picked, if any —, the chat on it from now. */
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
     * The ticket opened, about the service — one support deleted since it was picked is no reason to lose the customer's
     * words: then it is opened about none.
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

    /** A later message, written in the ticket — a closed one opens again —, the chat still on it. */
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
     * What the customer sent, as a ticket's message takes it: its words — a text, or a picture's caption — and its picture;
     * null for anything else.
     *
     * @return array{string, Attachment|null}|null
     */
    private function sent(Context $ctx): ?array
    {
        $update = $ctx->update;
        $caption = trim((string) (($update->message() ?? [])['caption'] ?? ''));
        $document = $update->document();

        return match ($update->contentKind()) {
            'text' => [trim((string) $update->text()), null],
            'photo' => [$caption, Attachment::telegram((string) ($update->photo()['file_id'] ?? ''))],
            'document' => $document !== null && $this->pictures->isPicture($document)
                ? [$caption, Attachment::telegram((string) ($document['file_id'] ?? ''), CustomerPictures::cleanName(isset($document['file_name']) ? (string) $document['file_name'] : null))]
                : null,
            default => null,
        };
    }

    /**
     * A picture without words: kept for the next text, which says what it is — and that asked for. Another picture of the
     * album the kept one came in (Telegram sends an album a picture at a time) is not asked about again: one picture goes
     * with a message, the first one kept.
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

    /** The picture held in the chat's scratch, for the next message's words — with the album it came in. */
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
     * The message refused — too short to open a ticket with, or the shop's own refusal in its words —, the message still
     * awaited; a picture that came with it is held for the next one, which the refusal says.
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

    /** The shop's refusal — the words too long, the ticket full, too many tickets or messages a while —, in its words. */
    private static function reason(ValidationException|TooManyAttemptsException $e): string
    {
        return $e instanceof ValidationException ? (array_values($e->errors())[0][0] ?? $e->getMessage()) : $e->getMessage();
    }

    /** Whether the chat came to its ticket, or wrote in it, within REPLY_SECONDS. */
    private static function lately(ChatSession $session): bool
    {
        $at = $session->get('at');

        return is_int($at) && now()->getTimestamp() - $at <= self::REPLY_SECONDS;
    }

    /** @return array{inline_keyboard: list<list<array<string, mixed>>>} «🗂️ مشاهده تیکت» — the ticket's screen in place of the word. */
    private function view(Ticket $ticket, int $from): array
    {
        return InlineKeyboard::make()->row(InlineKeyboard::callback($this->texts->get(BotText::TicketView), TicketHandler::screenCallback($ticket->id, $from)))->build();
    }
}
