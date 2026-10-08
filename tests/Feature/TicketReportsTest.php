<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\Tickets;
use App\Modules\Support\Tasks\PruneTicketsTask;
use App\Modules\Telegram\Api\Limits;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\GroupButtons;
use App\Modules\Telegram\Reports\ReportSender;
use App\Modules\Telegram\Reports\TicketClose;
use App\Modules\Telegram\Tasks\SendReportsTask;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Update\Update;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;
use Tests\Support\FakeTelegram;

/**
 * The tickets topic of the report group: a new ticket is reported — its picture as the photo the report captions — with
 * the bot admins' «🔒 بستن تیکت»; every later message of it, the customer's and support's answers from a panel, comes as
 * a reply under the ticket's last report, which hands its button down, so the group reads the whole conversation. A bot
 * admin's reply to any of a ticket's reports — its words, or a photo with its caption — is support's answer, the
 * customer told as from a panel; anyone else, an anonymous admin, a reply without words are told why not. A ticket's
 * reports are kept while it is not closed, so a reply a week on still answers it; a reply in the topic to a message of the
 * bot's that names no ticket is told where an answer goes; a reply to anything else is none of the tickets' business.
 */
final class TicketReportsTest extends BotTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @var array<string, int> */
    private array $threads;

    private User $ali;

    private Tickets $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->threads = $this->reportGroup();
        $this->admin(['telegram_id' => self::GROUP_ADMIN, 'username' => 'boss']);
        $this->ali = $this->customer(['telegram_id' => 5252, 'username' => 'ali']);
        $this->tickets = $this->service(Tickets::class);
    }

    public function testANewTicketIsReportedWithItsPictureAndTheButtonThatClosesIt(): void
    {
        $ticket = $this->open(Attachment::upload((string) base64_decode(self::PNG), 'png', 'screen.png'));

        self::assertSame(1, $this->sender()->flush());

        self::assertSame(['sendPhoto'], $this->telegram()->calls());
        $photo = $this->telegram()->params(0);
        $kept = (string) file_get_contents($this->app()->container()->get('tickets.path') . '/' . TicketMessage::query()->sole()->attachment_path);
        self::assertSame([[1, 1, 'image/png'], $kept], [self::imageOf($kept), $this->telegram()->files(0)['photo'] ?? null], 'the picture as the shop keeps it, by its bytes');
        self::assertSame([(string) self::REPORT_GROUP, (string) $this->threads['tickets'], 'HTML'], [$photo['chat_id'], $photo['message_thread_id'], $photo['parse_mode']]);
        self::assertStringContainsString("🎫 <b>تیکت جدید</b> · #{$ticket->id}", $photo['caption']);
        self::assertStringContainsString('<a href="tg://user?id=5252">Ali</a> · @ali', $photo['caption']);
        self::assertStringContainsString('📌 موضوع: قطعی اتصال', $photo['caption']);
        self::assertStringContainsString('سرویس وصل نمی‌شود.', $photo['caption']);
        self::assertSame(TicketClose::buttons($ticket->id), FakeTelegram::markupOf($photo)['inline_keyboard'] ?? null);
    }

    public function testALongMessageIsCutToWhatTelegramTakesTheWholeOnThePanel(): void
    {
        $long = str_repeat('پیام بلند ', 400);
        $this->tickets->open($this->ali, ['subject' => 'قطعی اتصال', 'body' => $long], Attachment::upload((string) base64_decode(self::PNG), 'png', null), TicketChannel::Web);

        $text = ReportMessage::query()->sole()->text;

        self::assertLessThanOrEqual(1024 - 64, mb_strlen(html_entity_decode(strip_tags($text))), "a photo's caption");
        self::assertStringEndsWith('…' . "\n\n↩️ با ریپلای روی همین پیام (یا هر پیام بعدی این تیکت) پاسخ دهید — یا در صفحه «پشتیبانی» در پنل.", $text);
        self::assertSame(trim($long), TicketMessage::query()->sole()->body, 'the whole is kept');

        // Emoji are two units each to Telegram: cut by its count, never past a caption's.
        $this->tickets->open($this->ali, ['subject' => 'قطعی اتصال', 'body' => str_repeat('👍 ', 1000)], Attachment::upload((string) base64_decode(self::PNG), 'png', null), TicketChannel::Web);
        self::assertLessThanOrEqual(Limits::CAPTION, TelegramHtml::visibleLength(ReportMessage::query()->latest('id')->firstOrFail()->text));
    }

    public function testTheConversationIsThreadedUnderTheTicketsLastReport(): void
    {
        $ticket = $this->open();
        $this->sender()->flush();
        $first = (int) ReportMessage::query()->sole()->message_id;

        $this->tickets->write($ticket, ['body' => 'هنوز وصل نمی‌شود.'], null, TicketChannel::Web);
        $this->telegram()->reset();
        $this->sender()->flush();

        self::assertSame(['sendMessage', 'editMessageReplyMarkup'], $this->telegram()->calls());
        self::assertSame($first, $this->telegram()->replyTarget(0), 'under the ticket');
        self::assertStringContainsString("💬 <b>پیام مشتری</b> · تیکت #{$ticket->id} · از وب‌سایت", $this->telegram()->params(0)['text']);
        self::assertSame(TicketClose::buttons($ticket->id), $this->inlineKeyboard(0), 'the button handed down');
        self::assertSame([], $this->buttonsUnder($first), 'the last report loses its own');
        $second = (int) ReportMessage::query()->latest('id')->firstOrFail()->message_id;

        $this->tickets->answer($ticket->refresh(), $this->panelActor(), ['body' => 'بررسی می‌کنیم.'], null);
        $this->tickets->close($ticket->refresh(), null);
        $this->tickets->rate($ticket->refresh(), ['rating' => 3, 'note' => 'کند بود']);
        $this->telegram()->reset();
        self::assertSame(3, $this->sender()->flush());

        $texts = array_values(array_map(static fn(array $call): string => $call['params']['text'], array_filter($this->telegram()->history, static fn(array $call): bool => $call['method'] === 'sendMessage')));
        self::assertStringContainsString("↩️ <b>پاسخ پشتیبانی</b> · تیکت #{$ticket->id} · از پنل", $texts[0]);
        self::assertSame("🔒 تیکت #{$ticket->id} بسته شد — مشتری آن را بست.", $texts[1]);
        self::assertSame("⭐️ امتیاز مشتری به تیکت #{$ticket->id}: ۳ از ۵\n📝 توضیح مشتری: کند بود", $texts[2]);
        self::assertSame($second, $this->telegram()->replyTarget(0), 'each a reply to the one before');
    }

    /**
     * While a ticket's last report still waits in the queue — a busy group, or Telegram holding it back —, the ticket's
     * next messages go in it: a burst is one report, not a queue of them crowding the group's other news out.
     */
    public function testABurstOfATicketsMessagesIsOneReportWhileTheLastOneWaits(): void
    {
        $ticket = $this->open();
        foreach (['هنوز وصل نمی‌شود.', 'روی وای‌فای هم امتحان کردم.', 'لطفا زودتر جواب دهید.'] as $body) {
            $this->tickets->write($ticket->refresh(), ['body' => $body], null, TicketChannel::Web);
        }

        $waiting = ReportMessage::waiting()->sole();
        self::assertSame($ticket->id, $waiting->ticket_id);
        self::assertSame(1, $this->sender()->flush());
        self::assertSame(['sendMessage'], $this->telegram()->calls(), 'one message for the four');
        $text = $this->telegram()->params(0)['text'];
        foreach (['سرویس وصل نمی‌شود.', 'هنوز وصل نمی‌شود.', 'روی وای‌فای هم امتحان کردم.', 'لطفا زودتر جواب دهید.'] as $body) {
            self::assertStringContainsString($body, $text);
        }
        self::assertSame(3, substr_count($text, "💬 <b>پیام مشتری</b> · تیکت #{$ticket->id}"), 'each message under its own line');
        self::assertSame(TicketClose::buttons($ticket->id), $this->inlineKeyboard(0));

        // Sent, it is the ticket's last report: the next message goes under it, a report of its own.
        $post = (int) $waiting->refresh()->message_id;
        $this->tickets->write($ticket->refresh(), ['body' => 'وصل شد، ممنون.'], null, TicketChannel::Web);
        $this->telegram()->reset();
        self::assertSame(1, $this->sender()->flush());
        self::assertSame($post, $this->telegram()->replyTarget(0));
    }

    /** A message with a picture of its own, or a last report a sender holds right now, is a report of its own. */
    public function testAMessageFoldsOnlyIntoAReportThatCanStillTakeIt(): void
    {
        $ticket = $this->open();
        $this->tickets->write($ticket->refresh(), ['body' => 'این هم تصویر خطا.'], Attachment::upload((string) base64_decode(self::PNG), 'png', 'error.png'), TicketChannel::Web);
        self::assertSame(2, ReportMessage::waiting()->count(), 'a picture is a photo of its own');

        $this->tickets->write($ticket->refresh(), ['body' => 'روی گوشی هم همین است.'], null, TicketChannel::Web);
        self::assertSame(3, ReportMessage::waiting()->count(), 'nothing folds into a photo: its caption has a quarter of the room');

        ReportMessage::waiting()->latest('id')->firstOrFail()->forceFill(['lease_token' => 'a-sender', 'leased_until' => now()->addMinute()])->save();
        $this->tickets->write($ticket->refresh(), ['body' => 'هنوز منتظرم.'], null, TicketChannel::Web);
        self::assertSame(4, ReportMessage::waiting()->count(), 'one a sender holds is going out as it is');
        self::assertStringNotContainsString('هنوز منتظرم.', (string) ReportMessage::query()->where('lease_token', 'a-sender')->value('text'));
    }

    public function testABotAdminsReplyToATicketsReportIsSupportsAnswer(): void
    {
        $ticket = $this->open();
        $this->sender()->flush();
        $post = (int) ReportMessage::query()->sole()->message_id;

        $this->send($this->reply($post, 'سلام، مشکل برطرف شد.'));

        $answer = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertSame([TicketAuthor::Support, '@boss', TicketChannel::Group, 'سلام، مشکل برطرف شد.'], [$answer->author, $answer->reviewer, $answer->channel, $answer->body]);
        self::assertSame([TicketStatus::Answered, true], [$ticket->refresh()->status, $ticket->customer_unread]);
        self::assertSame([self::text(BotText::TicketAnswered, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال', 'answer' => 'سلام، مشکل برطرف شد.', 'picture' => ''])], $this->telegram()->sentTo(5252), 'the customer told, as from a panel');
        self::assertSame(['sendMessage', 'sendMessage'], $this->calls());
        self::assertSame(['✅ پاسخ برای مشتری فرستاده شد.'], $this->telegram()->sentTo(self::REPORT_GROUP));
        self::assertSame(77, $this->telegram()->replyTarget(1), "under the admin's reply");
        self::assertSame((string) $this->threads['tickets'], $this->params(1)['message_thread_id']);
        self::assertSame(1, ReportMessage::query()->count(), 'what was written in the group is not reported to it again');
    }

    public function testAPhotoWithItsCaptionIsAnAnswerWithItsPicture(): void
    {
        $ticket = $this->open();
        $this->sender()->flush();
        $post = (int) ReportMessage::query()->sole()->message_id;
        $photo = ['photo' => [['file_id' => 'small', 'width' => 90], ['file_id' => 'large', 'width' => 1280]]];

        $this->send($this->reply($post, null, $photo + ['caption' => 'این تنظیم را بزنید.']));

        $answer = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertSame(['این تنظیم را بزنید.', 'large', 'photo.jpg', null], [$answer->body, $answer->attachment_file_id, $answer->attachment_name, $answer->attachment_path]);
        self::assertStringContainsString(trim(self::text(BotText::TicketAnswerPicture)), $this->telegram()->sentTo(5252)[0] ?? '');

        $this->send($this->reply($post, null, $photo));
        self::assertSame(['همراه تصویر توضیح هم بنویسید (کپشن)؛ مشتری پاسخ را با آن می‌خواند.'], $this->telegram()->sentTo(self::REPORT_GROUP));
        $this->send($this->reply($post, null, ['sticker' => ['file_id' => 'sticker-1'], 'caption' => 'سلام']));
        self::assertSame(['پاسخ را به صورت متن، یا تصویر همراه توضیح (کپشن) بفرستید.'], $this->telegram()->sentTo(self::REPORT_GROUP));
        $this->send($this->reply($post, str_repeat('م', Tickets::BODY_MAX + 1)));
        self::assertSame(['پیام حداکثر 4000 کاراکتر است.'], $this->telegram()->sentTo(self::REPORT_GROUP));
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count(), 'none of those answered it');
    }

    public function testOnlyTheBotsAdminsAnswerAndNeverAnonymously(): void
    {
        $ticket = $this->open();
        $this->sender()->flush();
        $post = (int) ReportMessage::query()->sole()->message_id;

        $this->send($this->reply($post, 'من هم جواب می‌دهم', from: self::GROUP_MEMBER));
        self::assertSame(['فقط مدیرهای ربات می‌توانند به تیکت پاسخ دهند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.'], $this->telegram()->sentTo(self::REPORT_GROUP));

        $this->send($this->reply($post, 'ناشناس', ['sender_chat' => ['id' => self::REPORT_GROUP, 'type' => 'supergroup']]));
        self::assertSame([GroupButtons::ANONYMOUS], $this->telegram()->sentTo(self::REPORT_GROUP));

        self::assertSame([1, TicketStatus::Open], [TicketMessage::query()->where('ticket_id', $ticket->id)->count(), $ticket->refresh()->status]);
        self::assertSame([], $this->telegram()->sentTo(5252), 'the customer told nothing');
    }

    public function testRepliesToAnythingElseAreNoneOfItsBusiness(): void
    {
        $ticket = $this->open();
        $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000'), $this->cardMethod()));
        $this->sender()->flush();
        $receiptPost = (int) ReportMessage::query()->where('ref', 'like', 'receipt:%')->sole()->message_id;
        $ticketPost = (int) ReportMessage::query()->where('ref', "ticket:{$ticket->id}")->sole()->message_id;

        foreach ([
            "a receipt's report, in its topic" => $this->groupReply('🧾 رسید جدید', 'این رسید درست است', $this->threads['receipts'], $receiptPost),
            "a message of the tickets topic that replies to nobody's (the topic's own first)" => $this->reply($this->threads['tickets'], 'سلام به همه'),
            "a member's message" => $this->reply($ticketPost, 'جواب به یک عضو', repliedFrom: self::GROUP_MEMBER),
        ] as $what => $update) {
            $this->send($update);
            self::assertSame([], $this->telegram()->calls(), "{$what}: nothing said, nothing done");
        }
        self::assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    public function testAReplyInTheTicketsTopicToAMessageOfTheBotsThatIsNoTicketsIsToldWhereAnAnswerGoes(): void
    {
        $ticket = $this->open();
        $this->sender()->flush();
        $post = (int) ReportMessage::query()->sole()->message_id;
        $this->send($this->reply($post, 'سلام، مشکل برطرف شد.'));
        $confirmation = 9999;

        // The review's: a reply to the bot's own word under an answer was dropped without a word.
        $this->send($this->reply($confirmation, 'یک نکته دیگر هم بگویم'));

        self::assertSame(['این پیام به تیکتی وصل نیست و پاسخی فرستاده نشد؛ روی گزارش همان تیکت ریپلای کنید، یا از صفحه «پشتیبانی» در پنل پاسخ دهید.'], $this->telegram()->sentTo(self::REPORT_GROUP));
        self::assertSame(77, $this->telegram()->replyTarget(0), "under the admin's reply");
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count(), 'nothing written');
    }

    public function testTheReportsOfATicketNotClosedAreKeptSoAReplyAWeekOnStillAnswersIt(): void
    {
        $ticket = $this->open();
        $closed = $this->open();
        $this->sender()->flush();
        $posts = ReportMessage::query()->pluck('message_id', 'ticket_id')->all();
        $this->tickets->close($closed->refresh(), null);
        $this->sender()->flush();

        // The review's: eight days on, the hourly housekeeping had pruned the week-old report, and an answer was dropped.
        Carbon::setTestNow(now()->addDays(8));
        $this->service(SendReportsTask::class)->run();
        $this->service(PruneTicketsTask::class)->run();

        self::assertSame([$ticket->id], array_values(array_unique(ReportMessage::query()->pluck('ticket_id')->all())), "the closed ticket's are forgotten a week on; the open one's kept");
        $this->send($this->reply((int) $posts[$ticket->id], 'سلام، مشکل برطرف شد.'));
        self::assertSame(['سلام، مشکل برطرف شد.', TicketStatus::Answered], [TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail()->body, $ticket->refresh()->status], 'answered all the same');
        self::assertSame(['✅ پاسخ برای مشتری فرستاده شد.'], $this->telegram()->sentTo(self::REPORT_GROUP));

        $this->send($this->reply((int) $posts[$closed->id], 'یک سوال دیگر'));
        self::assertStringContainsString('این پیام به تیکتی وصل نیست', $this->telegram()->sentTo(self::REPORT_GROUP)[0] ?? '', "a closed ticket's week-old report is gone: said where an answer goes");
    }

    public function testTheCloseButtonClosesItForTheBotsAdminsAndTellsTheCustomer(): void
    {
        $ticket = $this->open();
        $this->sender()->flush();
        $post = (int) ReportMessage::query()->sole()->message_id;

        $this->send($this->groupTap(TicketClose::PREFIX . $ticket->id, $post, $this->threads['tickets'], self::GROUP_MEMBER));
        self::assertSame(['فقط مدیرهای ربات می‌توانند تیکت را ببندند؛ نقش «مدیر ربات» در صفحه کاربران پنل داده می‌شود.', TicketStatus::Open], [$this->popup(), $ticket->refresh()->status]);

        $this->send($this->groupTap(TicketClose::PREFIX . $ticket->id, $post, $this->threads['tickets']));

        self::assertSame([TicketStatus::Closed, '🔒 تیکت بسته شد.'], [$ticket->refresh()->status, $this->popup()]);
        self::assertSame([], $this->buttonsUnder($post));
        self::assertSame([self::text(BotText::TicketClosed, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال'])], $this->telegram()->sentTo(5252), 'the customer told');
        $closing = ReportMessage::query()->latest('id')->firstOrFail();
        self::assertSame(["🔒 تیکت #{$ticket->id} بسته شد — پشتیبانی آن را بست.", "ticket:{$ticket->id}", true, null], [$closing->text, $closing->reply_ref, $closing->clears_buttons, $closing->keyboard]);

        $this->send($this->groupTap(TicketClose::PREFIX . $ticket->id, $post, $this->threads['tickets']));
        self::assertSame(Tickets::CLOSED, $this->popup(), 'a stale button decides nothing');
        self::assertSame([], $this->buttonsUnder($post));

        $this->send($this->groupTap(TicketClose::PREFIX . '999', $post, $this->threads['tickets']));
        self::assertSame('این تیکت پیدا نشد.', $this->popup());
    }

    private function open(?Attachment $picture = null): Ticket
    {
        return $this->tickets->open($this->ali, ['subject' => 'قطعی اتصال', 'body' => 'سرویس وصل نمی‌شود.'], $picture, TicketChannel::Web);
    }

    private function sender(): ReportSender
    {
        return $this->service(ReportSender::class);
    }

    /**
     * A message in the tickets topic answering the report `$post`, of the bot's unless told otherwise.
     *
     * @param array<string, mixed> $extra
     */
    private function reply(int $post, ?string $text, array $extra = [], int $from = self::GROUP_ADMIN, int $repliedFrom = FakeTelegram::BOT_ID): Update
    {
        return $this->groupReply('🎫 تیکت جدید', $text, $this->threads['tickets'], $post, $from, $extra, $repliedFrom);
    }
}
