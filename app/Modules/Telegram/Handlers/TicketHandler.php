<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Handlers;

use App\Core\Exceptions\TooManyAttemptsException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\BotSettings;
use App\Modules\Telegram\Context;
use App\Modules\Telegram\Handler;
use App\Modules\Telegram\Keyboard\Buttons;
use App\Modules\Telegram\Keyboard\InlineKeyboard;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\BotTexts;
use App\Modules\Telegram\Texts\Html;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Update\CallbackData;
use App\Support\Persian;
use App\Support\Text;

/**
 * «☎️ پشتیبانی» and the customer's support tickets in the bot — the screens. What the customer writes in a ticket is
 * TicketMessageHandler's, and every rule the shop's one service's (Support\Services\Tickets: the website's and the
 * panels' too); this renders:
 *   menu:support                  → home(): the support contact as the admin gave it (BotSettings) — or that none is —,
 *                                   with «📨 تیکت جدید» and «🗂️ تیکت‌های من»
 *   ticket:new                    → which of their services it is about — their running ones, the newest NEW_SERVICES,
 *                                   and «بدون سرویس مشخص» —, or straight on for a customer without one
 *   ticket:new:{service}          → its first message awaited (TicketMessageHandler); 0 for no service
 *   ticket:list:{page}            → «تیکت‌های من»: the latest activity first, PER_PAGE a page, each «#12 · subject»
 *                                   marked by where it stands
 *   ticket:{id}:{from}            → the ticket: its subject, where it stands, its service and rating, and its last SHOWN
 *                                   messages (support's «پشتیبانی», theirs «شما», each with its time; a picture numbered,
 *                                   «🖼️ تصویر n» under the screen sending it) — read by now (Tickets::markRead()); «بازگشت»
 *                                   to the page `from` of the list (0: its first)
 *   ticket:{id}:{from}:reply      → their next message awaited — a closed ticket opens again with it
 *   ticket:{id}:{from}:close      → closed, while it is open or answered
 *   ticket:{id}:{from}:rate[:{n}] → a closed ticket rated, 1 to 5
 *   ticket:{id}:{from}:picture:{message} → that message's picture, as a photo of its own (the screen stays)
 * and a service's «⚠️ ارسال گزارش اختلال» (SubscriptionHandler's `sub:{id}:report`) → report(): a new ticket about that
 * service, its first message awaited.
 * A button under a notice of the ticket (support's answer, its closing — CustomerNotifier) carries `from` NOTICE: what it
 * opens comes as a message of its own, so the notice stays — support's words with it. Every tap here is navigation (a
 * message half written is left behind). Only the customer's own tickets answer (Ticket::of()): another's is one that is
 * not there.
 */
final class TicketHandler implements Handler
{
    public const PREFIX = 'ticket:';

    /** «📨 تیکت جدید»: which of their services the ticket is about first. */
    public const START = 'ticket:new';

    /** Tickets per page of «تیکت‌های من». */
    private const PER_PAGE = 5;

    /** The services a new ticket offers: the customer's running ones, the newest first. */
    private const NEW_SERVICES = 5;

    /** The messages a ticket's screen shows: the last ones. */
    private const SHOWN = 5;

    /** The most of a message's words the screen shows, as Telegram counts them; the whole is on the shop's website and its panels. */
    private const WORDS_SHOWN = 600;

    /** The most of its subject a ticket's button shows. */
    private const BUTTON_SUBJECT = 40;

    /** A margin kept under Telegram's limit, beside what it counts (Limits counts as it does: an emoji two units). */
    private const ROOM = 96;

    private const NEW = 'new';
    private const LIST = 'list';
    /** The `from` of a button under a notice: what it opens comes as a message of its own. */
    private const NOTICE = 'n';
    private const REPLY = 'reply';
    private const CLOSE = 'close';
    private const RATE = 'rate';
    private const PICTURE = 'picture';

    public function __construct(
        private readonly Tickets $tickets,
        private readonly TicketAttachments $attachments,
        private readonly BotSettings $settings,
        private readonly MainMenu $menu,
        private readonly Buttons $buttons,
        private readonly BotTexts $texts,
    ) {}

    /** «تیکت‌های من» at that page. */
    public static function listCallback(int $page): string
    {
        return CallbackData::build(self::PREFIX, self::LIST, $page);
    }

    /**
     * The ticket's screen — opened from that page of «تیکت‌های من», 0 from anywhere else —, `$action` one of its buttons
     * (reply, close, rate…).
     */
    public static function screenCallback(int $ticketId, int $from, string ...$action): string
    {
        return CallbackData::build(self::PREFIX, $ticketId, $from, ...$action);
    }

    /** Under a notice of the ticket: its screen, as a message of its own. */
    public static function viewFromNotice(int $ticketId): string
    {
        return CallbackData::build(self::PREFIX, $ticketId, self::NOTICE);
    }

    /** Under support's answer: the customer's own awaited. */
    public static function replyFromNotice(int $ticketId): string
    {
        return CallbackData::build(self::PREFIX, $ticketId, self::NOTICE, self::REPLY);
    }

    /** Under the ticket's closing: its rating. */
    public static function rateFromNotice(int $ticketId): string
    {
        return CallbackData::build(self::PREFIX, $ticketId, self::NOTICE, self::RATE);
    }

    public function handle(Context $ctx): void
    {
        $args = $ctx->update->callbackArgs(self::PREFIX) ?? [];

        match ($args[0] ?? '') {
            self::NEW => isset($args[1]) ? $this->ask($ctx, (int) $args[1], self::START) : $this->services($ctx),
            self::LIST => $this->list($ctx, (int) ($args[1] ?? 1)),
            default => $this->ticket($ctx, $args),
        };
    }

    /**
     * «پشتیبانی» (MainMenu::SUPPORT): the contact the admin gave support — or that none is set —, as it ever was, and the
     * tickets: a new one, and the customer's own.
     */
    public function home(Context $ctx): void
    {
        $contact = $this->settings->supportContact();
        $text = $contact !== ''
            ? $this->texts->render(BotText::SupportContact, ['contact' => $contact])
            : $this->texts->get(BotText::SupportUnavailable);

        // Reading order: «تیکت جدید» first, on the right — Telegram lays a row out left to right.
        $this->menu->screen($ctx, $text, InlineKeyboard::make()->row(
            InlineKeyboard::callback($this->texts->get(BotText::TicketMine), self::listCallback(1)),
            $this->newButton(),
        ));
    }

    /**
     * «⚠️ ارسال گزارش اختلال» on a service's screen (SubscriptionHandler): a new ticket about that service — its first
     * message awaited, as a new ticket's is (TicketMessageHandler) —, asked for in the outage report's words, «بازگشت» to
     * the service's screen.
     */
    public function report(Context $ctx, Subscription $service): void
    {
        TicketMessageHandler::awaitNew($ctx->session, $service->id);

        $ctx->edit($this->texts->render(BotText::TicketReportAsk, ['client' => $service->remote_name]), ['reply_markup' => $this->buttons->backOnly(SubscriptionHandler::serviceCallback($service->id))]);
    }

    /**
     * «📨 تیکت جدید»: which of their services the ticket is about — the running ones, the newest NEW_SERVICES, named as
     * the panel names them, and «بدون سرویس مشخص» —; a customer without one goes straight on to the message.
     */
    private function services(Context $ctx): void
    {
        $services = Subscription::active()->where('user_id', $ctx->user->id)->latest('id')->limit(self::NEW_SERVICES)->get(['id', 'remote_name']);
        if ($services->isEmpty()) {
            $this->ask($ctx, 0, MainMenu::SUPPORT);

            return;
        }

        $keyboard = InlineKeyboard::make();
        foreach ($services as $service) {
            $keyboard->row(InlineKeyboard::callback($this->texts->render(BotText::ServiceButton, ['client' => $service->remote_name]), CallbackData::build(self::PREFIX, self::NEW, $service->id)));
        }
        $keyboard->row(InlineKeyboard::callback($this->texts->get(BotText::TicketNoService), CallbackData::build(self::PREFIX, self::NEW, 0)))
            ->row($this->buttons->back(MainMenu::SUPPORT));

        $ctx->edit($this->texts->get(BotText::TicketPickService), ['reply_markup' => $keyboard->build()]);
    }

    /**
     * A new ticket's first message awaited (TicketMessageHandler) — about that service of the customer's, or none (0) —,
     * «بازگشت» to the step before (`$back`): the services, or «پشتیبانی» when there were none to pick.
     */
    private function ask(Context $ctx, int $serviceId, string $back): void
    {
        $service = $serviceId > 0 ? Subscription::query()->where('user_id', $ctx->user->id)->find($serviceId) : null;
        TicketMessageHandler::awaitNew($ctx->session, $service?->id);

        $ctx->edit($this->texts->render(BotText::TicketAsk, [
            'service' => $service !== null ? $this->texts->part(BotText::TicketService, ['client' => $service->remote_name]) : '',
        ]), ['reply_markup' => $this->buttons->backOnly($back)]);
    }

    /**
     * «تیکت‌های من»: the customer's tickets, the latest activity first, a page at a time — each a button «#12 · subject»
     * marked by where it stands —, «بعدی» / «قبلی» under them when there is more than a page and the page's number under
     * the title; none yet, a word and «📨 تیکت جدید». «بازگشت» to «پشتیبانی».
     */
    private function list(Context $ctx, int $page): void
    {
        $mine = Ticket::of($ctx->user);
        $total = (clone $mine)->count();
        if ($total === 0) {
            $ctx->edit($this->texts->get(BotText::TicketsEmpty), ['reply_markup' => InlineKeyboard::make()->row($this->newButton())->row($this->buttons->back(MainMenu::SUPPORT))->build()]);

            return;
        }

        $pages = (int) ceil($total / self::PER_PAGE);
        $page = max(1, min($page, $pages));

        $keyboard = InlineKeyboard::make();
        foreach ($mine->orderByDesc('last_message_at')->orderByDesc('id')->forPage($page, self::PER_PAGE)->get(['id', 'subject', 'status']) as $ticket) {
            $label = $this->texts->render(match ($ticket->status) {
                TicketStatus::Open => BotText::TicketButtonOpen,
                TicketStatus::Answered => BotText::TicketButtonAnswered,
                TicketStatus::Closed => BotText::TicketButtonClosed,
            }, ['ticket' => $ticket->id, 'subject' => Text::fit($ticket->subject, self::BUTTON_SUBJECT)]);
            $keyboard->row(InlineKeyboard::callback($label, self::screenCallback($ticket->id, $page)));
        }

        $text = $this->texts->get(BotText::TicketsTitle);
        if ($pages > 1) {
            $keyboard->row(...$this->buttons->pages($page, $pages, self::listCallback(...)));
            $text .= $this->texts->part(BotText::SubscriptionsPage, ['page' => Persian::digits($page), 'pages' => Persian::digits($pages)]);
        }
        $keyboard->row($this->buttons->back(MainMenu::SUPPORT));

        $ctx->edit($text, ['reply_markup' => $keyboard->build()]);
    }

    /** @param list<string> $args The ticket, where it was opened from, the button pressed and its value */
    private function ticket(Context $ctx, array $args): void
    {
        $fresh = ($args[1] ?? null) === self::NOTICE;
        $from = $fresh ? 0 : max(0, (int) ($args[1] ?? 0));
        $ticket = Ticket::of($ctx->user)->find((int) ($args[0] ?? 0));
        if ($ticket === null) {
            $this->put($ctx, $fresh, $this->texts->get(BotText::TicketNotFound), $this->buttons->backOnly(self::listCallback(max(1, $from))));

            return;
        }

        match ($args[2] ?? null) {
            self::REPLY => $this->reply($ctx, $ticket, $from, $fresh),
            self::CLOSE => $this->close($ctx, $ticket, $from),
            self::RATE => isset($args[3]) ? $this->rate($ctx, $ticket, $from, (int) $args[3]) : $this->stars($ctx, $ticket, $from, $fresh),
            self::PICTURE => $this->picture($ctx, $ticket, (int) ($args[3] ?? 0)),
            default => $this->show($ctx, $ticket, $from, $fresh),
        };
    }

    /** The ticket's screen, read by now: support's latest words are on it. */
    private function show(Context $ctx, Ticket $ticket, int $from, bool $fresh): void
    {
        $this->tickets->markRead($ticket);
        [$text, $pictures] = $this->screen($ticket);
        $this->put($ctx, $fresh, $text, $this->keyboard($ticket, $from, $pictures));
    }

    /**
     * «✍️ پاسخ»: their next message awaited (TicketMessageHandler) — a closed ticket opens again with it, which the prompt
     * says —, «بازگشت» to the ticket. Support's latest words are read by now: the customer is answering them.
     */
    private function reply(Context $ctx, Ticket $ticket, int $from, bool $fresh): void
    {
        $this->tickets->markRead($ticket);
        TicketMessageHandler::awaitReply($ctx->session, $ticket, $from);

        $this->put($ctx, $fresh, $this->texts->render(BotText::TicketReplyAsk, [
            'ticket' => $ticket->id,
            'reopens' => $ticket->status === TicketStatus::Closed ? $this->texts->part(BotText::TicketReplyReopens) : '',
        ]), $this->buttons->backOnly(self::screenCallback($ticket->id, $from)));
    }

    /**
     * «🔒 بستن تیکت»: closed by the customer — the report group hears it, nobody else —, the screen redrawn; one support
     * closed a moment ago is said so.
     */
    private function close(Context $ctx, Ticket $ticket, int $from): void
    {
        try {
            $this->tickets->close($ticket, null);
            $ctx->answer($this->texts->get(BotText::TicketClosedByCustomer));
        } catch (ValidationException) {
            // The move that lost read the ticket again: the screen shows it as it stands.
            $ctx->answer($this->texts->get(BotText::TicketAlreadyClosed), alert: true);
        }

        $this->show($ctx, $ticket, $from, fresh: false);
    }

    /**
     * «⭐ امتیاز»: the stars, 1 to 5, «بازگشت» to the ticket — under a notice, as a message of its own. A ticket opened
     * again meanwhile has nothing to rate until it closes.
     */
    private function stars(Context $ctx, Ticket $ticket, int $from, bool $fresh): void
    {
        if ($ticket->status !== TicketStatus::Closed) {
            $ctx->answer($this->texts->get(BotText::TicketRateUnavailable), alert: true);
            $this->show($ctx, $ticket, $from, $fresh);

            return;
        }

        $stars = array_map(fn(int $rating): array => InlineKeyboard::callback(
            $this->texts->render(BotText::TicketStar, ['rating' => Persian::digits($rating)]),
            self::screenCallback($ticket->id, $from, self::RATE, (string) $rating),
        ), range(1, 5));
        // Reading order: 1 on the right to 5 on the left — Telegram lays a row out left to right.
        $keyboard = InlineKeyboard::make()->row(...array_reverse($stars))->row($this->buttons->back(self::screenCallback($ticket->id, $from)));

        $this->put($ctx, $fresh, $this->texts->render(BotText::TicketRateAsk, ['ticket' => $ticket->id]), $keyboard->build());
    }

    /**
     * A star pressed: the closed ticket rated — the report group hears a first rating or a changed one —, the screen
     * redrawn with its rating. A rating is a message of the customer's window: past it, the wait is said.
     */
    private function rate(Context $ctx, Ticket $ticket, int $from, int $rating): void
    {
        try {
            $this->tickets->rate($ticket, ['rating' => $rating]);
            $ctx->answer($this->texts->get(BotText::TicketRatedThanks));
        } catch (ValidationException $e) {
            if (isset($e->errors()['status'])) {
                // A message of theirs, or support's answer, opened it again meanwhile: nothing to rate until it closes.
                $ctx->answer($this->texts->get(BotText::TicketRateUnavailable), alert: true);
                $ticket->refresh();
            }
        } catch (TooManyAttemptsException $e) {
            $ctx->answer($e->getMessage(), alert: true);
        }

        $this->show($ctx, $ticket, $from, fresh: false);
    }

    /**
     * «🖼️ تصویر n»: a message's picture, as a photo of its own — the screen stays —, said whose message it went with and
     * when; one that is not there any more (Telegram no longer hands it over, its file gone) is said so.
     */
    private function picture(Context $ctx, Ticket $ticket, int $messageId): void
    {
        $message = TicketMessage::query()->where('ticket_id', $ticket->id)->find($messageId);
        $caption = $message === null ? '' : $this->texts->render(BotText::TicketPictureCaption, [
            'when' => Persian::date($message->created_at, withTime: true),
            'ticket' => $ticket->id,
        ]);
        $options = TelegramHtml::visibleLength($caption) <= Limits::CAPTION ? ['caption' => $caption] : [];
        if ($message === null || !$message->keepsPicture() || !$this->attachments->send($ctx->chatId(), $message, $options, fetch: true)) {
            $ctx->answer($this->texts->get(BotText::TicketPictureGone), alert: true);
        }
    }

    /**
     * The ticket worded: its number and subject, where it stands, the service it is about and the customer's rating, and
     * its last SHOWN messages, the earliest of them first — as many as a message holds with the rest (the earliest left
     * out first), a line saying how many came before them —; and the messages shown whose picture is there to send, in
     * the order they are numbered.
     *
     * @return array{string, list<TicketMessage>}
     */
    private function screen(Ticket $ticket): array
    {
        $total = TicketMessage::query()->where('ticket_id', $ticket->id)->count();
        $latest = array_reverse(TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->limit(self::SHOWN)->get()->all());
        $service = $ticket->subscription;
        $values = [
            'ticket' => $ticket->id,
            'subject' => $ticket->subject,
            'status' => $this->texts->part(match ($ticket->status) {
                TicketStatus::Open => BotText::TicketStatusOpen,
                TicketStatus::Answered => BotText::TicketStatusAnswered,
                TicketStatus::Closed => BotText::TicketStatusClosed,
            }),
            'service' => $service !== null ? $this->texts->part(BotText::TicketService, ['client' => $service->remote_name]) : '',
            'rated' => $ticket->rating !== null ? $this->texts->part(BotText::TicketRating, ['rating' => Persian::digits($ticket->rating)]) : '',
        ];
        $room = Limits::MESSAGE - self::ROOM;
        $render = fn(array $shown, int $words): string => $this->texts->render(BotText::TicketScreen, $values + [
            'messages' => $this->conversation($shown, $total, $words),
        ]);
        $pictured = static fn(array $shown): array => array_values(array_filter($shown, static fn(TicketMessage $message): bool => $message->keepsPicture()));

        for ($count = count($latest); $count > 1; --$count) {
            $shown = array_slice($latest, -$count);
            $text = $render($shown, self::WORDS_SHOWN);
            if (TelegramHtml::visibleLength($text) <= $room) {
                return [$text, $pictured($shown)];
            }
        }

        // The latest message alone — its words cut by as much as the admin's wording leaves no room for.
        $shown = array_slice($latest, -1);
        $text = $render($shown, self::WORDS_SHOWN);
        $over = TelegramHtml::visibleLength($text) - $room;

        return [$over > 0 ? $render($shown, max(0, self::WORDS_SHOWN - $over)) : $text, $pictured($shown)];
    }

    /**
     * The ticket's messages on its screen, a part each — whose (theirs «شما», support's «پشتیبانی»), when, its words cut
     * to `$words` as Telegram counts them (Limits::fit()), its picture numbered as its «🖼️ تصویر» button is (or said to be no longer kept) —, under a
     * line saying how many earlier ones are not shown.
     *
     * @param list<TicketMessage> $messages
     */
    private function conversation(array $messages, int $total, int $words): Html
    {
        $lines = [];
        if ($total > count($messages)) {
            $lines[] = $this->texts->part(BotText::TicketEarlier, ['earlier' => Persian::number($total - count($messages))]);
        }
        $pictures = 0;
        foreach ($messages as $message) {
            $lines[] = $this->texts->part($message->author === TicketAuthor::Support ? BotText::TicketFromSupport : BotText::TicketFromCustomer, [
                'when' => Persian::date($message->created_at, withTime: true),
                'message' => Limits::fit($message->body, $words),
                'picture' => match (true) {
                    $message->keepsPicture() => $this->texts->part(BotText::TicketMessagePicture, ['number' => Persian::digits(++$pictures)]),
                    $message->hasPicture() => $this->texts->part(BotText::TicketPictureRemoved),
                    default => '',
                },
            ]);
        }

        return Html::lines(...$lines);
    }

    /**
     * Rows as Telegram lays them out, left to right — for a right-to-left reader the first button of a row is the one on
     * the right: «🖼️ تصویر n» for each picture shown, 1 on the right; «پاسخ», beside it «بستن تیکت» while the ticket is
     * open or answered, or «امتیاز» once it is closed and not yet rated; «بازگشت» to the page of the list it was opened from.
     *
     * @param list<TicketMessage> $pictures
     * @return array<string, mixed>
     */
    private function keyboard(Ticket $ticket, int $from, array $pictures): array
    {
        $id = $ticket->id;
        $keyboard = InlineKeyboard::make();
        if ($pictures !== []) {
            $keyboard->row(...array_reverse(array_map(fn(TicketMessage $message, int $at): array => InlineKeyboard::callback(
                $this->texts->render(BotText::TicketPicture, ['number' => Persian::digits($at + 1)]),
                self::screenCallback($id, $from, self::PICTURE, (string) $message->id),
            ), $pictures, array_keys($pictures))));
        }
        $row = match (true) {
            $ticket->status !== TicketStatus::Closed => [InlineKeyboard::callback($this->texts->get(BotText::TicketClose), self::screenCallback($id, $from, self::CLOSE))],
            $ticket->rating === null => [InlineKeyboard::callback($this->texts->get(BotText::TicketRate), self::screenCallback($id, $from, self::RATE), 'success')],
            default => [],
        };
        $row[] = InlineKeyboard::callback($this->texts->get(BotText::TicketReply), self::screenCallback($id, $from, self::REPLY), 'primary');

        return $keyboard
            ->row(...$row)
            ->row($this->buttons->back(self::listCallback(max(1, $from))))
            ->build();
    }

    /** @return array<string, mixed> «📨 تیکت جدید» — on «پشتیبانی», and under a list with no ticket yet. */
    private function newButton(): array
    {
        return InlineKeyboard::callback($this->texts->get(BotText::TicketNew), self::START, 'primary');
    }

    /**
     * A screen in place of the tapped message — or, under a notice (`$fresh`), a message of its own: the notice stays.
     *
     * @param array<string, mixed> $keyboard
     */
    private function put(Context $ctx, bool $fresh, string $text, array $keyboard): void
    {
        if ($fresh) {
            $ctx->reply($text, ['reply_markup' => $keyboard]);
        } else {
            $ctx->edit($text, ['reply_markup' => $keyboard]);
        }
    }
}
