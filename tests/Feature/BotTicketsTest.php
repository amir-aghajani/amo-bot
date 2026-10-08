<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Bots\CurrentBot;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\Tickets;
use App\Modules\Support\Tasks\PruneTicketsTask;
use App\Modules\Telegram\Handlers\TicketHandler;
use App\Modules\Telegram\Keyboard\MainMenu;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Reports\TicketClose;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use App\Support\Persian;
use App\Support\Validation;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * Support tickets in the bot. «پشتیبانی» offers a new ticket and the customer's own. A new one asks which of their running
 * services it is about (none to pick: straight on), then takes one message — words, or a picture with them: a photo, or a
 * picture sent as a file, judged by its bytes; one without words, or with words refused, is kept for the next words —
 * whose first line is its subject; the chat stays on the ticket, so what follows joins it, until the customer goes
 * elsewhere, a quarter of an hour passes without a message, or a notice of another ticket of theirs comes. «تیکت‌های من»
 * lists them, the latest activity first, five a page; a ticket's screen shows where it stands and its last messages, read
 * by now — its notices too —, each picture numbered and sent by its «🖼️ تصویر» button, with «✍️ پاسخ» (a closed one
 * opens again with it), «🔒 بستن تیکت» and, once closed, «⭐ امتیاز» — each star a message of the customer's window, the
 * group hearing only a first rating or a changed one. Support's answer comes with its picture and both notices with their
 * buttons, each opening a message of its own beside the notice. The report group hears of a ticket opened in the bot as
 * of one opened on the website; the shop's limits are said in its words.
 */
final class BotTicketsTest extends BotTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $ali;

    private Tickets $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 09:00:00');
        $this->ali = $this->customer(['username' => 'ali']);
        $this->tickets = $this->service(Tickets::class);
    }

    public function testSupportOffersANewTicketAndTheCustomersOwnBesideWhatItSaid(): void
    {
        $this->send($this->tap(MainMenu::SUPPORT));

        self::assertSame([self::text(BotText::SupportUnavailable)], $this->said(), 'no contact set: the tickets under it are the way to support');
        self::assertSame([[
            ['text' => self::text(BotText::TicketMine), 'callback_data' => TicketHandler::listCallback(1)],
            ['text' => self::text(BotText::TicketNew), 'callback_data' => TicketHandler::START, 'style' => 'primary'],
        ]], $this->inlineKeyboard(0), '«تیکت جدید» first, on the right — and no inline back while the menu is under the field');
    }

    public function testANewTicketOpensWithItsFirstMessageAndWhatFollowsJoinsIt(): void
    {
        $this->send($this->tap(TicketHandler::START));
        self::assertSame([self::text(BotText::TicketAsk, ['service' => ''])], $this->said(), 'no service to pick: straight on');
        self::assertSame([MainMenu::SUPPORT], $this->callbacks(0), '«بازگشت» to «پشتیبانی»');

        $this->send($this->message("قطعی اتصال\nاز دیشب سرویس من وصل نمی‌شود."));

        $ticket = Ticket::query()->sole();
        self::assertSame(['قطعی اتصال', TicketStatus::Open, null], [$ticket->subject, $ticket->status, $ticket->subscription_id], 'its first line the subject');
        $message = TicketMessage::query()->sole();
        self::assertSame([TicketAuthor::Customer, TicketChannel::Bot, "قطعی اتصال\nاز دیشب سرویس من وصل نمی‌شود.", null], [$message->author, $message->channel, $message->body, $message->attachment_file_id]);
        self::assertSame([self::text(BotText::TicketOpened, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال'])], $this->said());
        self::assertSame([[['text' => self::text(BotText::TicketView), 'callback_data' => TicketHandler::screenCallback($ticket->id, 0)]]], $this->inlineKeyboard(0));

        $this->send($this->message('مودم را هم خاموش و روشن کردم.'));
        self::assertSame(['مودم را هم خاموش و روشن کردم.', TicketChannel::Bot], [TicketMessage::query()->latest('id')->firstOrFail()->body, TicketMessage::query()->latest('id')->firstOrFail()->channel], 'what follows joins the ticket');
        self::assertSame(1, Ticket::query()->count());
        self::assertSame([self::text(BotText::TicketMessageSent, ['ticket' => (string) $ticket->id])], $this->said());

        // Going elsewhere leaves it.
        $this->send($this->tap(MainMenu::SUPPORT));
        $this->send($this->message('سلام'));
        self::assertSame([self::text(BotText::Unknown)], $this->said());
        self::assertSame(2, TicketMessage::query()->count());
    }

    public function testANewTicketAsksWhichOfTheCustomersRunningServicesItIsAbout(): void
    {
        $server = $this->fakeServer();
        $plan = $this->plan();
        $services = [];
        foreach (range(1, 6) as $n) {
            $services[$n] = $this->subscription($this->ali, $plan, $server, "ali_{$n}");
        }
        $this->subscription($this->ali, $plan, $server, 'ali_old', ['status' => SubscriptionStatus::Expired]);

        $this->send($this->tap(TicketHandler::START));

        self::assertSame([self::text(BotText::TicketPickService)], $this->said());
        $rows = $this->inlineKeyboard(0);
        self::assertSame(
            array_map(static fn(string $name): string => self::text(BotText::ServiceButton, ['client' => $name]), ['ali_6', 'ali_5', 'ali_4', 'ali_3', 'ali_2']),
            array_column(array_merge(...array_slice($rows, 0, 5)), 'text'),
            'the newest five running ones, named as the panel names them',
        );
        self::assertSame([self::text(BotText::TicketNoService), self::text(BotText::Back)], [$rows[5][0]['text'], $rows[6][0]['text']]);
        self::assertSame(MainMenu::SUPPORT, $rows[6][0]['callback_data']);

        $this->send($this->tap($rows[0][0]['callback_data']));
        self::assertSame([self::text(BotText::TicketAsk, ['service' => self::text(BotText::TicketService, ['client' => 'ali_6'])])], $this->said());
        self::assertSame([TicketHandler::START], $this->callbacks(0), '«بازگشت» to the services');
        $this->send($this->message('سرعت سرویس پایین است'));
        self::assertSame($services[6]->id, Ticket::query()->sole()->subscription_id);

        $this->send($this->tap(TicketHandler::START));
        $this->send($this->tap($this->inlineKeyboard(0)[5][0]['callback_data']));
        self::assertSame([self::text(BotText::TicketAsk, ['service' => ''])], $this->said());
        $this->send($this->message('یک سوال درباره خرید'));
        self::assertNull(Ticket::query()->latest('id')->firstOrFail()->subscription_id, '«بدون سرویس مشخص»');
    }

    public function testAPhotoGoesWithItsCaption(): void
    {
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message(null, ['photo' => [['file_id' => 'small', 'width' => 90], ['file_id' => 'large', 'width' => 1280]], 'caption' => 'این خطا را می‌دهد']));

        $message = TicketMessage::query()->sole();
        self::assertSame(['این خطا را می‌دهد', 'large', 'photo.jpg', null], [$message->body, $message->attachment_file_id, $message->attachment_name, $message->attachment_path], 'the largest size, kept by its file id');
        self::assertSame('این خطا را می‌دهد', Ticket::query()->sole()->subject);
    }

    public function testAPictureSentAsAFileIsJudgedByItsBytes(): void
    {
        $this->send($this->tap(TicketHandler::START));

        // It says it is a picture, but it is a web page: refused, the message still awaited.
        $this->telegram()->reply(['file_path' => 'documents/a.jpg']);
        $this->telegram()->raw(new Response(200, [], '<html><script>alert(1)</script></html>'));
        $this->send($this->message(null, ['document' => ['file_id' => 'html-1', 'file_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 40], 'caption' => 'خطای صفحه']));
        self::assertSame(['getFile', 'a.jpg', 'sendMessage'], $this->calls(), 'its bytes fetched and looked at');
        self::assertSame([self::text(BotText::TicketTextOnly)], $this->said());
        self::assertSame(0, Ticket::query()->count());

        // A real PNG sent as a file is a picture, under its own name.
        $this->telegram()->reply(['file_path' => 'documents/s.png']);
        $this->telegram()->raw(new Response(200, [], (string) base64_decode(self::PNG, true)));
        $this->send($this->message(null, ['document' => ['file_id' => 'png-1', 'file_name' => 'screen.png', 'mime_type' => 'image/png', 'file_size' => 68], 'caption' => 'این صفحه خطا است']));
        $message = TicketMessage::query()->sole();
        self::assertSame(['این صفحه خطا است', 'png-1', 'screen.png'], [$message->body, $message->attachment_file_id, $message->attachment_name]);
    }

    public function testAPictureWithoutWordsIsKeptAndItsWordsAskedFor(): void
    {
        $this->send($this->tap(TicketHandler::START));

        $this->send($this->message(null, ['photo' => [['file_id' => 'first']], 'media_group_id' => 'album-1']));
        self::assertSame([self::text(BotText::TicketPictureNeedsWords)], $this->said());
        self::assertSame(0, Ticket::query()->count(), 'the shop takes no picture without words');

        // The album's next picture is not asked about again: one picture goes with a message.
        $this->send($this->message(null, ['photo' => [['file_id' => 'second']], 'media_group_id' => 'album-1']));
        self::assertSame([], $this->calls());

        $this->send($this->message('صفحه پرداخت باز نمی‌شود'));
        $message = TicketMessage::query()->sole();
        self::assertSame(['صفحه پرداخت باز نمی‌شود', 'first'], [$message->body, $message->attachment_file_id], 'the words with the picture kept');
        self::assertSame('صفحه پرداخت باز نمی‌شود', Ticket::query()->sole()->subject);

        // The next message of the ticket is words alone: the picture went with the last one.
        $this->send($this->message('یک توضیح دیگر'));
        self::assertNull(TicketMessage::query()->latest('id')->firstOrFail()->attachment_file_id);
    }

    public function testWhatIsNeitherWordsNorAPictureIsRefusedTheMessageStillAwaited(): void
    {
        $this->send($this->tap(TicketHandler::START));

        foreach ([
            'a voice' => ['voice' => ['file_id' => 'v-1']],
            'a sticker' => ['sticker' => ['file_id' => 's-1']],
            'a PDF' => ['document' => ['file_id' => 'pdf-1', 'file_name' => 'log.pdf', 'mime_type' => 'application/pdf'], 'caption' => 'لاگ برنامه'],
        ] as $what => $sent) {
            $this->send($this->message(null, $sent));
            self::assertSame([self::text(BotText::TicketTextOnly)], $this->said(), $what);
            self::assertSame(['sendMessage'], $this->calls(), "{$what}: refused without being downloaded");
        }
        self::assertSame(0, Ticket::query()->count());

        $this->send($this->message('سرویس وصل نمی‌شود'));
        self::assertSame(1, Ticket::query()->count(), 'still awaited');
    }

    public function testTheSubjectIsTheFirstLineCutToFit(): void
    {
        // Too short to be a subject, the message whole: more is asked for.
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message('hi'));
        self::assertSame([self::text(BotText::TicketTooShort, ['kept' => ''])], $this->said());
        self::assertSame(0, Ticket::query()->count());

        // A first line too short takes the next words with it.
        $this->send($this->message("؟\nسرویسم   وصل نمی‌شود"));
        self::assertSame('؟ سرویسم وصل نمی‌شود', Ticket::query()->sole()->subject);
        self::assertSame("؟\nسرویسم   وصل نمی‌شود", TicketMessage::query()->sole()->body, 'the message as it was written');

        // A first line too long is cut at a space near the end, «…» after it.
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message(str_repeat('کلمه ', 40) . "\nادامه"));
        $subject = Ticket::query()->latest('id')->firstOrFail()->subject;
        self::assertSame(implode(' ', array_fill(0, 23, 'کلمه')) . '…', $subject);
        self::assertLessThanOrEqual(Tickets::SUBJECT_MAX, mb_strlen($subject));
    }

    public function testTheShopsLimitsAreSaidInItsWords(): void
    {
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message(str_repeat('ب', Tickets::BODY_MAX + 1)));
        self::assertSame([self::text(BotText::TicketRefused, ['reason' => Validation::tooLong('پیام', Tickets::BODY_MAX), 'kept' => ''])], $this->said());
        self::assertSame(0, Ticket::query()->count());

        // Ten opened in an hour — on the website too —: the next waits, the message still awaited.
        foreach (range(1, Tickets::OPENS) as $n) {
            $this->tickets->open($this->ali, ['subject' => "سوال {$n}", 'body' => 'سلام'], null, TicketChannel::Web);
        }
        $this->send($this->message('یک سوال دیگر'));
        self::assertSame([self::text(BotText::TicketRefused, ['reason' => 'تیکت زیادی باز کرده‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.', 'kept' => ''])], $this->said());
        self::assertSame(Tickets::OPENS, Ticket::query()->count());

        // Writing in one is another window: thirty messages in ten minutes, the tickets' first among them.
        $ticket = Ticket::query()->latest('id')->firstOrFail();
        foreach (range(1, Tickets::MESSAGES - Tickets::OPENS) as $n) {
            $this->tickets->write($ticket, ['body' => "پیام {$n}"], null, TicketChannel::Web);
        }
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'reply')));
        $this->send($this->message('باز هم'));
        self::assertSame([self::text(BotText::TicketRefused, ['reason' => 'پیام زیادی فرستاده‌اید؛ ۱۰ دقیقه دیگر دوباره امتحان کنید.', 'kept' => ''])], $this->said());

        Carbon::setTestNow(now()->addMinutes(11));
        $this->send($this->message('حالا'));
        self::assertSame([self::text(BotText::TicketMessageSent, ['ticket' => (string) $ticket->id])], $this->said(), 'the window over: the message was still awaited');
    }

    public function testMyTicketsListsTheLatestActivityFirstFiveAPage(): void
    {
        $long = str_repeat('الف', 20);
        $tickets = [];
        foreach (range(1, 7) as $n) {
            $tickets[$n] = $this->ticket($this->ali, $n === 7 ? $long : "موضوع {$n}", 'متن', ['last_message_at' => now()->subHours(10 - $n)] + match ($n) {
                2 => ['status' => TicketStatus::Closed],
                3 => ['status' => TicketStatus::Answered],
                default => [],
            });
        }
        $this->ticket($this->customer(['telegram_id' => 7272]), 'تیکت مشتری دیگر');

        $this->send($this->tap(TicketHandler::listCallback(1)));

        self::assertSame([self::text(BotText::TicketsTitle) . self::text(BotText::SubscriptionsPage, ['page' => '۱', 'pages' => '۲'])], $this->said(), "seven of theirs — another's is not theirs —: two pages");
        $rows = $this->inlineKeyboard(0);
        self::assertSame([
            self::button(BotText::TicketButtonOpen, $tickets[7], mb_substr($long, 0, 39) . '…'),
            self::button(BotText::TicketButtonOpen, $tickets[6]),
            self::button(BotText::TicketButtonOpen, $tickets[5]),
            self::button(BotText::TicketButtonOpen, $tickets[4]),
            self::button(BotText::TicketButtonAnswered, $tickets[3]),
        ], array_column(array_merge(...array_slice($rows, 0, 5)), 'text'), 'the latest activity first, each marked by where it stands, a long subject cut');
        self::assertSame(TicketHandler::screenCallback($tickets[7]->id, 1), $rows[0][0]['callback_data'], 'its screen goes back to this page');
        self::assertSame([['text' => self::text(BotText::PageNext), 'callback_data' => TicketHandler::listCallback(2)]], $rows[5]);
        self::assertSame([['text' => self::text(BotText::Back), 'callback_data' => MainMenu::SUPPORT]], $rows[6], 'back to «پشتیبانی»');

        $this->send($this->tap(TicketHandler::listCallback(2)));
        self::assertStringEndsWith(self::text(BotText::SubscriptionsPage, ['page' => '۲', 'pages' => '۲']), $this->said()[0]);
        $rows = $this->inlineKeyboard(0);
        self::assertSame([self::button(BotText::TicketButtonClosed, $tickets[2]), self::button(BotText::TicketButtonOpen, $tickets[1])], array_column(array_merge(...array_slice($rows, 0, 2)), 'text'));
        self::assertSame([['text' => self::text(BotText::PagePrev), 'callback_data' => TicketHandler::listCallback(1)]], $rows[2]);
    }

    public function testNoTicketYetOffersANewOne(): void
    {
        $this->send($this->tap(TicketHandler::listCallback(1)));

        self::assertSame([self::text(BotText::TicketsEmpty)], $this->said());
        self::assertSame([
            [['text' => self::text(BotText::TicketNew), 'callback_data' => TicketHandler::START, 'style' => 'primary']],
            [['text' => self::text(BotText::Back), 'callback_data' => MainMenu::SUPPORT]],
        ], $this->inlineKeyboard(0));
    }

    public function testATicketsScreenShowsWhereItStandsAndItsLastMessagesReadByNow(): void
    {
        $service = $this->subscription($this->ali, $this->plan(), $this->fakeServer(), 'ali_1');
        $ticket = $this->ticket($this->ali, 'قطعی اتصال', 'سرویس وصل نمی‌شود.', ['subscription_id' => $service->id]);
        $this->tickets->answer($ticket, $this->panelActor(), ['body' => 'بررسی می‌کنیم.'], null);
        $this->tickets->write($ticket->refresh(), ['body' => 'منتظرم.'], null, TicketChannel::Web);
        $this->tickets->answer($ticket->refresh(), $this->groupAdminActor(), ['body' => 'این تنظیم را بزنید.'], Attachment::telegram('guide'));
        $this->tickets->write($ticket->refresh(), ['body' => 'زدم، نشد.'], null, TicketChannel::Web);
        $this->tickets->answer($ticket->refresh(), $this->panelActor(), ['body' => 'سرور را عوض کردیم.'], null);
        $this->tickets->write($ticket->refresh(), ['body' => 'الان وصل شد، ممنون.'], null, TicketChannel::Web);
        $this->tickets->answer($ticket->refresh(), $this->panelActor(), ['body' => 'خواهش می‌کنم.'], null);
        self::assertTrue($ticket->refresh()->customer_unread);

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 2)));

        $messages = TicketMessage::query()->where('ticket_id', $ticket->id)->orderBy('id')->get()->all();
        self::assertCount(8, $messages);
        $line = static fn(TicketMessage $message): string => self::text($message->author === TicketAuthor::Support ? BotText::TicketFromSupport : BotText::TicketFromCustomer, [
            'when' => Persian::date($message->created_at, withTime: true),
            'message' => $message->body,
            'picture' => $message->hasPicture() ? self::text(BotText::TicketMessagePicture, ['number' => '۱']) : '',
        ]);
        self::assertSame([self::text(BotText::TicketScreen, [
            'ticket' => (string) $ticket->id,
            'subject' => 'قطعی اتصال',
            'status' => self::text(BotText::TicketStatusAnswered),
            'service' => self::text(BotText::TicketService, ['client' => 'ali_1']),
            'rated' => '',
            'messages' => implode("\n", [self::text(BotText::TicketEarlier, ['earlier' => '۳']), ...array_map($line, array_slice($messages, 3))]),
        ])], $this->said(), 'the last five, the earliest of them first — the picture numbered');
        self::assertStringContainsString(trim(self::text(BotText::TicketMessagePicture, ['number' => '۱'])), $this->said()[0]);
        self::assertSame(['editMessageText', 'answerCallbackQuery'], $this->calls(), 'nothing sent but the screen');
        self::assertFalse($ticket->refresh()->customer_unread, "support's latest words read by now");
        self::assertSame([4, 0], [Notification::query()->count(), Notification::query()->whereNull('read_at')->count()], "and the notices of them, in their website's feed");
        self::assertSame([
            [['text' => self::text(BotText::TicketPicture, ['number' => '۱']), 'callback_data' => TicketHandler::screenCallback($ticket->id, 2, 'picture', (string) $messages[3]->id)]],
            [
                ['text' => self::text(BotText::TicketClose), 'callback_data' => TicketHandler::screenCallback($ticket->id, 2, 'close')],
                ['text' => self::text(BotText::TicketReply), 'callback_data' => TicketHandler::screenCallback($ticket->id, 2, 'reply'), 'style' => 'primary'],
            ],
            [['text' => self::text(BotText::Back), 'callback_data' => TicketHandler::listCallback(2)]],
        ], $this->inlineKeyboard(0), '«🖼️ تصویر ۱» over them, «پاسخ» on the right, «بستن تیکت» beside it; back to the page it came from');
    }

    public function testAnotherCustomersTicketIsNotThere(): void
    {
        $theirs = $this->ticket($this->customer(['telegram_id' => 7272]), 'تیکت مشتری دیگر');

        $this->send($this->tap(TicketHandler::screenCallback($theirs->id, 1)));
        self::assertSame([self::text(BotText::TicketNotFound)], $this->said());
        self::assertSame([TicketHandler::listCallback(1)], $this->callbacks(0), 'the way back');

        foreach (['close', 'reply'] as $action) {
            $this->send($this->tap(TicketHandler::screenCallback($theirs->id, 1, $action)));
            self::assertSame([self::text(BotText::TicketNotFound)], $this->said(), $action);
        }
        $this->send($this->message('این را به تیکت او بیفزا'));
        self::assertSame([TicketStatus::Open, 1], [$theirs->refresh()->status, TicketMessage::query()->where('ticket_id', $theirs->id)->count()], 'nothing written in it, nothing closed');
    }

    public function testAReplyIsTheCustomersNextMessageWordsOrAPicture(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال', 'وصل نمی‌شود.', ['status' => TicketStatus::Answered, 'customer_unread' => true]);

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'reply')));
        self::assertSame([self::text(BotText::TicketReplyAsk, ['ticket' => (string) $ticket->id, 'reopens' => ''])], $this->said());
        self::assertSame([TicketHandler::screenCallback($ticket->id, 1)], $this->callbacks(0), '«بازگشت» to the ticket');
        self::assertFalse($ticket->refresh()->customer_unread, "support's words read: the customer is answering them");

        $this->send($this->message('هنوز وصل نمی‌شود.'));
        $message = TicketMessage::query()->latest('id')->firstOrFail();
        self::assertSame([TicketAuthor::Customer, TicketChannel::Bot, 'هنوز وصل نمی‌شود.'], [$message->author, $message->channel, $message->body]);
        self::assertSame(TicketStatus::Open, $ticket->refresh()->status, 'waiting on support again');
        self::assertSame([self::text(BotText::TicketMessageSent, ['ticket' => (string) $ticket->id])], $this->said());
        self::assertSame([TicketHandler::screenCallback($ticket->id, 1)], $this->callbacks(0), 'the screen goes back to the page it came from');

        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-1']], 'caption' => 'این تصویر خطاست']));
        $message = TicketMessage::query()->latest('id')->firstOrFail();
        self::assertSame(['این تصویر خطاست', 'shot-1'], [$message->body, $message->attachment_file_id]);
    }

    public function testAMessageToAClosedTicketOpensItAgain(): void
    {
        $ticket = $this->ticket($this->ali, 'سوال قبلی', 'سلام', ['status' => TicketStatus::Closed, 'closed_at' => now(), 'rating' => 5]);

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'reply')));
        self::assertSame([self::text(BotText::TicketReplyAsk, ['ticket' => (string) $ticket->id, 'reopens' => self::text(BotText::TicketReplyReopens)])], $this->said(), 'the prompt says so');

        $this->send($this->message('یک سوال دیگر دارم.'));

        $ticket->refresh();
        self::assertSame([TicketStatus::Open, null, null], [$ticket->status, $ticket->closed_at, $ticket->rating], 'its end and its rating left behind');
    }

    public function testTheCustomerClosesTheirTicket(): void
    {
        $this->reportGroup();
        $ticket = $this->ticket($this->ali);

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'close')));

        self::assertSame(TicketStatus::Closed, $ticket->refresh()->status);
        self::assertSame(self::text(BotText::TicketClosedByCustomer), $this->popup());
        self::assertStringContainsString('📊 وضعیت: ' . self::text(BotText::TicketStatusClosed), $this->said()[0], 'the screen redrawn');
        self::assertSame([
            TicketHandler::screenCallback($ticket->id, 1, 'rate'),
            TicketHandler::screenCallback($ticket->id, 1, 'reply'),
            TicketHandler::listCallback(1),
        ], $this->callbacks(1), 'closed: «امتیاز» beside «پاسخ»');
        self::assertSame([], $this->telegram()->sentTo(self::CHAT), 'their own doing tells them nothing');
        self::assertStringContainsString('مشتری آن را بست', ReportMessage::query()->latest('id')->firstOrFail()->text, 'the group hears it');

        // A button left from before.
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'close')));
        self::assertSame(self::text(BotText::TicketAlreadyClosed), $this->popup());
    }

    public function testAClosedTicketIsRatedOneToFiveStars(): void
    {
        $ticket = $this->ticket($this->ali, overrides: ['status' => TicketStatus::Closed, 'closed_at' => now()]);

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'rate')));
        self::assertSame([self::text(BotText::TicketRateAsk, ['ticket' => (string) $ticket->id])], $this->said());
        $rows = $this->inlineKeyboard(0);
        self::assertSame(
            array_map(static fn(int $n): array => ['text' => self::text(BotText::TicketStar, ['rating' => Persian::digits($n)]), 'callback_data' => TicketHandler::screenCallback($ticket->id, 1, 'rate', (string) $n)], [5, 4, 3, 2, 1]),
            $rows[0],
            'one on the right, five on the left',
        );
        self::assertSame([['text' => self::text(BotText::Back), 'callback_data' => TicketHandler::screenCallback($ticket->id, 1)]], $rows[1]);

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'rate', '4')));

        self::assertSame(4, $ticket->refresh()->rating);
        self::assertSame(self::text(BotText::TicketRatedThanks), $this->popup());
        self::assertStringContainsString(self::text(BotText::TicketRating, ['rating' => '۴']), $this->said()[0]);
        self::assertSame([TicketHandler::screenCallback($ticket->id, 1, 'reply'), TicketHandler::listCallback(1)], $this->callbacks(1), 'rated: no «امتیاز» any more');

        // Opened again meanwhile by a message of theirs: nothing to rate until it closes.
        $this->tickets->write($ticket, ['body' => 'یک سوال دیگر'], null, TicketChannel::Web);
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'rate', '5')));
        self::assertSame(self::text(BotText::TicketRateUnavailable), $this->popup());
        self::assertNull($ticket->refresh()->rating);
    }

    public function testSupportsAnswerAndClosingComeWithTheirButtonsEachOpeningAMessageOfItsOwn(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');
        $this->telegram()->reset();
        $this->tickets->answer($ticket, $this->panelActor(), ['body' => 'مشکل برطرف شد.'], null);

        self::assertSame([[
            ['text' => self::text(BotText::TicketView), 'callback_data' => TicketHandler::viewFromNotice($ticket->id)],
            ['text' => self::text(BotText::TicketReply), 'callback_data' => TicketHandler::replyFromNotice($ticket->id), 'style' => 'primary'],
        ]], $this->telegram()->markup(0)['inline_keyboard'] ?? null, '«پاسخ» on the right');

        // «✍️ پاسخ»: the answer awaited in a message of its own — the notice, support's words, stays.
        $this->send($this->tap(TicketHandler::replyFromNotice($ticket->id)));
        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertSame([self::text(BotText::TicketReplyAsk, ['ticket' => (string) $ticket->id, 'reopens' => ''])], $this->said());
        $this->send($this->message('ممنون، درست شد.'));
        self::assertSame(['ممنون، درست شد.', TicketStatus::Open], [TicketMessage::query()->latest('id')->firstOrFail()->body, $ticket->refresh()->status]);

        // «🗂️ مشاهده تیکت»: the screen, a message of its own too.
        $this->send($this->tap(TicketHandler::viewFromNotice($ticket->id)));
        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls());
        self::assertStringStartsWith("🎫 <b>تیکت #{$ticket->id}</b>", $this->said()[0]);
        self::assertSame(TicketHandler::listCallback(1), array_slice($this->callbacks(0), -1)[0], 'back to the list');

        // Support closes it: «⭐ امتیاز» while it is not rated, and the screen.
        $this->telegram()->reset();
        $this->tickets->close($ticket->refresh(), $this->panelActor());
        self::assertSame([[
            ['text' => self::text(BotText::TicketView), 'callback_data' => TicketHandler::viewFromNotice($ticket->id)],
            ['text' => self::text(BotText::TicketRate), 'callback_data' => TicketHandler::rateFromNotice($ticket->id), 'style' => 'success'],
        ]], $this->telegram()->markup(0)['inline_keyboard'] ?? null);

        $this->send($this->tap(TicketHandler::rateFromNotice($ticket->id)));
        self::assertSame(['sendMessage', 'answerCallbackQuery'], $this->calls(), 'the stars in a message of their own');
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 0, 'rate', '5')));
        self::assertSame(['answerCallbackQuery', 'editMessageText'], $this->calls(), 'the screen in place of the stars');
        self::assertSame(5, $ticket->refresh()->rating);
    }

    public function testTheReportGroupHearsOfATicketOpenedInTheBotAsOfOneOpenedOnTheWebsite(): void
    {
        $threads = $this->reportGroup();

        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message("قطعی اتصال\nاز دیشب وصل نمی‌شود."));
        $ticket = Ticket::query()->sole();
        $this->telegram()->reset();
        self::assertSame(1, $this->service(ReportSender::class)->flush());

        $report = $this->telegram()->params(0);
        self::assertSame((string) $threads['tickets'], $report['message_thread_id']);
        self::assertStringContainsString("🎫 <b>تیکت جدید</b> · #{$ticket->id}", $report['text']);
        self::assertStringContainsString('📌 موضوع: قطعی اتصال', $report['text']);
        self::assertStringContainsString('📨 از ربات', $report['text']);
        self::assertSame(TicketClose::buttons($ticket->id), FakeTelegram::markupOf($report)['inline_keyboard'] ?? null);

        // What follows, under it — a picture sent in Telegram said in a line.
        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-1']], 'caption' => 'این هم تصویرش']));
        $this->telegram()->reset();
        $this->service(ReportSender::class)->flush();
        $text = $this->telegram()->params(0)['text'];
        self::assertStringContainsString("💬 <b>پیام مشتری</b> · تیکت #{$ticket->id} · از ربات", $text);
        self::assertStringContainsString('🖼 همراه یک تصویر', $text);
    }

    public function testTheChatLeavesTheTicketAQuarterOfAnHourAfterItsLastMessage(): void
    {
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message("قطعی اتصال\nاز دیشب وصل نمی‌شود."));
        $ticket = Ticket::query()->sole();

        Carbon::setTestNow(now()->addMinutes(14));
        $this->send($this->message('مودم را هم خاموش و روشن کردم.'));
        self::assertSame(2, TicketMessage::query()->count(), 'within the quarter of an hour, it joins the ticket');

        Carbon::setTestNow(now()->addMinutes(15)->addSecond());
        $this->send($this->message('سلام'));
        self::assertSame([self::text(BotText::Unknown)], $this->said(), 'later, it is no ticket\'s: the fallback answers it');
        self::assertSame(2, TicketMessage::query()->count());

        // «✍️ پاسخ» is the way back in.
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'reply')));
        $this->send($this->message('هنوز وصل نمی‌شود.'));
        self::assertSame(['هنوز وصل نمی‌شود.', 3], [TicketMessage::query()->latest('id')->firstOrFail()->body, TicketMessage::query()->count()]);
    }

    public function testANoticeOfAnotherTicketEndsTheChatsStayOnATicketItsReplyButtonTheWayIn(): void
    {
        // The review's: the chat stayed on ticket A, and an answer meant for ticket B's notice landed in A and opened it again.
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message("قطعی اتصال\nاز دیشب وصل نمی‌شود."));
        $a = Ticket::query()->sole();
        $this->tickets->close($a, $this->panelActor());
        $b = $this->ticket($this->ali, 'خرید پلن', 'سوال درباره خرید');
        Carbon::setTestNow(now()->addMinute());
        $this->tickets->answer($b, $this->panelActor(), ['body' => 'بله، ممکن است.'], null);

        $this->send($this->message('ممنون، پس همین را می‌خرم'));

        self::assertSame([self::text(BotText::Unknown)], $this->said(), "no ticket's: the notice of B ended the stay on A");
        self::assertSame([TicketStatus::Closed, 1], [$a->refresh()->status, TicketMessage::query()->where('ticket_id', $a->id)->count()], 'A stays closed, as it was');

        $this->send($this->tap(TicketHandler::replyFromNotice($b->id)));
        $this->send($this->message('ممنون، پس همین را می‌خرم'));
        self::assertSame(['ممنون، پس همین را می‌خرم', TicketStatus::Open], [TicketMessage::query()->where('ticket_id', $b->id)->latest('id')->firstOrFail()->body, $b->refresh()->status], "the notice's «✍️ پاسخ» takes it to B");

        // A notice of the ticket the chat is on leaves it there.
        $this->tickets->answer($b->refresh(), $this->panelActor(), ['body' => 'خواهش می‌کنم.'], null);
        $this->send($this->message('یک سوال دیگر'));
        self::assertSame('یک سوال دیگر', TicketMessage::query()->where('ticket_id', $b->id)->latest('id')->firstOrFail()->body);
    }

    public function testAPictureWithWordsThatWereRefusedIsKeptForTheNextMessage(): void
    {
        $this->send($this->tap(TicketHandler::START));
        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-1']], 'caption' => 'hi']));

        self::assertSame([self::text(BotText::TicketTooShort, ['kept' => self::text(BotText::TicketPictureKept)])], $this->said(), 'too short to open a ticket with: the picture kept, and said so');
        $this->send($this->message('صفحه پرداخت باز نمی‌شود'));
        $message = TicketMessage::query()->sole();
        self::assertSame(['صفحه پرداخت باز نمی‌شود', 'shot-1'], [$message->body, $message->attachment_file_id], 'the next words took it');

        // Writing in the ticket: words too long are refused, the picture with them kept.
        $this->send($this->message(null, ['photo' => [['file_id' => 'shot-2']], 'caption' => str_repeat('ب', Tickets::BODY_MAX + 1)]));
        self::assertSame([self::text(BotText::TicketRefused, ['reason' => Validation::tooLong('پیام', Tickets::BODY_MAX), 'kept' => self::text(BotText::TicketPictureKept)])], $this->said());
        $this->send($this->message('این هم توضیح تصویر دوم'));
        self::assertSame(['این هم توضیح تصویر دوم', 'shot-2'], [TicketMessage::query()->latest('id')->firstOrFail()->body, TicketMessage::query()->latest('id')->firstOrFail()->attachment_file_id]);
    }

    public function testSupportsPictureReachesTheChatWithTheAnswerItsWordsTheCaption(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');
        $notice = fn(string $answer): string => self::text(BotText::TicketAnswered, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال', 'answer' => $answer, 'picture' => self::text(BotText::TicketAnswerPicture)]);
        $buttons = [[
            ['text' => self::text(BotText::TicketView), 'callback_data' => TicketHandler::viewFromNotice($ticket->id)],
            ['text' => self::text(BotText::TicketReply), 'callback_data' => TicketHandler::replyFromNotice($ticket->id), 'style' => 'primary'],
        ]];

        // The review's: support's picture never reached a customer who has the bot alone.
        $this->telegram()->reset();
        $this->tickets->answer($ticket, $this->groupAdminActor(), ['body' => 'این تنظیم را بزنید.'], Attachment::telegram('guide'));
        self::assertSame(['sendPhoto'], $this->telegram()->calls(), 'a photo the report group sent, by its file id');
        self::assertSame(['guide', $notice('این تنظیم را بزنید.')], [$this->telegram()->params(0)['photo'] ?? null, $this->telegram()->params(0)['caption'] ?? null]);
        self::assertSame($buttons, $this->telegram()->markup(0)['inline_keyboard'] ?? null);

        $this->telegram()->reset();
        $this->tickets->answer($ticket->refresh(), $this->panelActor(), ['body' => 'راهنما'], Attachment::upload((string) base64_decode(self::PNG, true), 'png', 'guide.png'));
        $kept = (string) file_get_contents($this->app()->container()->get('tickets.path') . '/' . TicketMessage::query()->latest('id')->firstOrFail()->attachment_path);
        self::assertSame(['sendPhoto', $kept, $notice('راهنما')], [$this->telegram()->calls()[0] ?? null, $this->telegram()->files(0)['photo'] ?? null, $this->telegram()->params(0)['caption'] ?? null], "a panel's upload, by its bytes");

        // Telegram turns the picture down, or the words are too long for a caption: the words alone.
        $this->telegram()->reset();
        $this->telegram()->fail(400, 'Bad Request: wrong file identifier/HTTP URL specified');
        $this->tickets->answer($ticket->refresh(), $this->groupAdminActor(), ['body' => 'دوباره'], Attachment::telegram('broken'));
        self::assertSame(['sendPhoto', 'sendMessage'], $this->telegram()->calls());
        self::assertSame($notice('دوباره'), $this->telegram()->params(1)['text'] ?? null, 'told all the same');
        $this->telegram()->reset();
        $long = str_repeat('توضیح ', 300);
        $this->tickets->answer($ticket->refresh(), $this->groupAdminActor(), ['body' => $long], Attachment::telegram('guide'));
        self::assertSame(['sendMessage'], $this->telegram()->calls());
    }

    public function testTheScreenSendsAMessagesPictureAsAMessageOfItsOwn(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');
        $this->tickets->answer($ticket, $this->groupAdminActor(), ['body' => 'این تنظیم را بزنید.'], Attachment::telegram('guide'));
        $picture = TicketMessage::query()->latest('id')->firstOrFail();

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'picture', (string) $picture->id)));

        self::assertSame(['sendPhoto', 'answerCallbackQuery'], $this->calls(), 'the screen stays');
        self::assertSame(['guide', self::text(BotText::TicketPictureCaption, ['when' => Persian::date($picture->created_at, withTime: true), 'ticket' => (string) $ticket->id])], [$this->params(0)['photo'] ?? null, $this->params(0)['caption'] ?? null]);

        // One Telegram no longer hands over — as a photo, nor as a file — is said so.
        $this->telegram()->fail(400, 'Bad Request: wrong file identifier/HTTP URL specified');
        $this->telegram()->fail(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'picture', (string) $picture->id)));
        self::assertSame(self::text(BotText::TicketPictureGone), $this->popup());

        // Another's message, or one without a picture, sends nothing.
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'picture', '999')));
        self::assertSame(self::text(BotText::TicketPictureGone), $this->popup());
    }

    public function testAPictureNoLongerKeptIsSaidSoOnTheScreenWithNothingToSend(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');
        $this->tickets->answer($ticket, $this->panelActor(), ['body' => 'این راهنماست.'], Attachment::upload((string) base64_decode(self::PNG, true), 'png', 'guide.png'));
        $this->tickets->close($ticket->refresh(), $this->panelActor());
        Carbon::setTestNow(now()->addDays(31));
        $this->service(PruneTicketsTask::class)->run();

        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1)));

        self::assertStringContainsString(trim(self::text(BotText::TicketPictureRemoved)), $this->said()[0]);
        self::assertNotContains(TicketHandler::screenCallback($ticket->id, 1, 'picture', (string) TicketMessage::query()->latest('id')->firstOrFail()->id), $this->callbacks(0), 'no picture to send');
    }

    public function testStarsAreMessagesOfTheWindowAndOnlyAFirstRatingOrAChangedOneIsReported(): void
    {
        $this->reportGroup();
        $ticket = $this->ticket($this->ali, overrides: ['status' => TicketStatus::Closed, 'closed_at' => now()]);
        // A second apart — a person's pace: the bot leaves a chat faster than that unanswered.
        $star = function (int $rating) use ($ticket): void {
            Carbon::setTestNow(now()->addSecond());
            $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1, 'rate', (string) $rating)));
        };
        $before = ReportMessage::query()->count();

        $star(4);
        $star(4);
        self::assertSame($before + 1, ReportMessage::query()->count(), 'the same star again tells nothing');
        $star(5);
        self::assertSame($before + 2, ReportMessage::query()->count());

        foreach (range(1, Tickets::MESSAGES - 3) as $n) {
            $star(1 + $n % 5);
        }
        $last = 1 + (Tickets::MESSAGES - 3) % 5;
        $star($last % 5 + 1);
        self::assertSame('پیام زیادی فرستاده‌اید؛ ۱۰ دقیقه دیگر دوباره امتحان کنید.', $this->popup(), 'thirty in ten minutes, then the wait');
        self::assertSame($last, $ticket->refresh()->rating, 'the refused one changed nothing');
    }

    public function testAnAgentsBotKeepsItsTicketsInItsOwnShop(): void
    {
        $bot = $this->agentBot();

        $this->sendTo($bot, $this->tap(TicketHandler::START));
        $this->sendTo($bot, $this->message('سوال از ربات نماینده'));

        self::assertSame(0, Ticket::query()->count(), "none in the main bot's shop");
        $ticket = CurrentBot::run($bot, static fn(): Ticket => Ticket::query()->sole());
        self::assertSame([$bot->id, 'سوال از ربات نماینده'], [$ticket->bot_id, $ticket->subject]);
        self::assertSame(FakeTelegram::AGENT_TOKEN, $this->telegram()->tokenOf(0), "the agent's bot answers");
    }

    public function testTheListAndATicketReadTheirRowsInAFixedFewQueries(): void
    {
        $ticket = $this->ticket($this->ali);
        $screens = [TicketHandler::listCallback(1), TicketHandler::screenCallback($ticket->id, 1)];
        $this->send($this->message('/start'));
        foreach ($screens as $screen) {
            $this->send($this->tap($screen));
        }

        $few = [];
        foreach ($screens as $screen) {
            $few[$screen] = count($this->queriesOf($this->tap($screen)));
        }

        // Many more tickets, and messages in this one — read once since, as the few were.
        foreach (range(1, 9) as $n) {
            $this->ticket($this->ali, "سوال {$n}");
            $this->tickets->answer($ticket->refresh(), $this->panelActor(), ['body' => "پاسخ {$n}"], null);
        }
        $this->send($this->tap(TicketHandler::screenCallback($ticket->id, 1)));

        foreach ($few as $screen => $queries) {
            self::assertLessThanOrEqual($queries, count($this->queriesOf($this->tap($screen))), "{$screen}: more rows, no more queries");
        }
    }

    /** The label of a ticket's button in «تیکت‌های من»: where it stands, its number and its subject (as it shows). */
    private static function button(BotText $text, Ticket $ticket, ?string $subject = null): string
    {
        return self::text($text, ['ticket' => (string) $ticket->id, 'subject' => $subject ?? $ticket->subject]);
    }

    /** @return list<string> The statements serving the update ran */
    private function queriesOf(Update $update): array
    {
        $queries = [];
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        }, fn() => $this->send($update));

        return $queries;
    }
}
