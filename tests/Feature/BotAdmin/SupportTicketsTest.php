<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Support\Enums\TicketAuthor;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Telegram\Admin\Sections\Support\TicketScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Tests\BotTestCase;

/**
 * «🎧 پشتیبانی» in the bot's management (Sections\Support\Tickets*): the tickets by the panel's tabs, one customer's or
 * a search's, a ticket's conversation a page at a time, support's answer — words, or a picture with its caption, one
 * Idempotency-Key a question —, the pictures sent at a tap, and closing and opening again asked first as the panel asks.
 */
final class SupportTicketsTest extends BotTestCase
{
    /** One white pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private const STAFF = 7100;

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->customer(['telegram_id' => self::STAFF, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
        $this->ali = $this->customer(['first_name' => 'Ali', 'username' => 'ali']);
    }

    public function testAnAdminAnswersAWaitingTicketFromTheList(): void
    {
        $ticket = $this->ticket($this->ali);

        $this->send($this->message('/admin', chat: self::STAFF));
        self::assertContains(Nav::to('tk'), $this->buttons(), 'the daily work: an admin\'s');

        $this->send($this->tap(Nav::to('tk'), chat: self::STAFF));
        self::assertStringContainsString('۱ تیکت · 🟡 در انتظار پاسخ', $this->lastSaid(), 'how many and the tab, over the rows');
        self::assertContains(Nav::to('tkv', 'show', $ticket->id), $this->buttons(), 'the waiting one on the first tab');

        $this->send($this->tap(Nav::to('tkv', 'show', $ticket->id), chat: self::STAFF));
        self::assertStringContainsString('قطعی اتصال', $this->lastSaid());
        self::assertStringContainsString('سرویس من وصل نمی‌شود.', $this->lastSaid());
        self::assertStringContainsString('وضعیت: <b>🟡 در انتظار پاسخ</b>', $this->lastSaid());
        self::assertNotNull($ticket->refresh()->support_read_at, 'opened, the customer\'s words read as read');
        self::assertSame(['primary', 'danger'], [$this->button(Nav::to('tkv', 'ans', $ticket->id))['style'] ?? null, $this->button(Nav::to('tkv', 'cls', $ticket->id))['style'] ?? null], 'the answer the main action, closing red');

        $this->send($this->tap(Nav::to('tkv', 'ans', $ticket->id), chat: self::STAFF));
        self::assertStringContainsString('پاسخ به مشتری', $this->lastSaid());
        self::assertStringContainsString('مشتری از پاسخ شما خبردار می‌شود', $this->lastSaid());

        $this->send($this->message('سلام، بررسی شد و درست است.', chat: self::STAFF));

        $answer = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertSame(['سلام، بررسی شد و درست است.', TicketAuthor::Support, TicketChannel::Manage], [$answer->body, $answer->author, $answer->channel]);
        self::assertNotNull($answer->request_key, 'under an Idempotency-Key');
        self::assertSame(TicketStatus::Answered, $ticket->refresh()->status);
        self::assertStringContainsString('✅ پاسخ فرستاده شد', implode("\n", $this->said()));
        self::assertStringContainsString('مدیریت ربات', implode("\n", $this->said()), 'where the answer was written, in the panel\'s word');
        $staff = User::query()->where('telegram_id', self::STAFF)->firstOrFail();
        self::assertSame('tg:' . self::STAFF, $answer->reviewer);
        self::assertStringContainsString('🎧 <b>پشتیبانی</b> · توسط ' . $staff->name() . ' · مدیریت ربات', $this->lastSaid(), 'who of support wrote it, by their account\'s name');
        self::assertStringNotContainsString('manage', implode("\n", $this->said()));
        self::assertContains((string) self::TELEGRAM_ID, array_column(array_column($this->telegram()->history, 'params'), 'chat_id'), 'the customer is told in the bot');
    }

    public function testAPictureGoesWithItsCaptionAndIsSentBackAtATap(): void
    {
        $ticket = $this->ticket($this->ali);
        $this->telegram()->on('getFile', static fn(): array => ['file_id' => 'photo-1', 'file_size' => 100, 'file_path' => 'photos/file_1.png']);
        $this->telegram()->on('file_1.png', static fn(): Response => new Response(200, ['Content-Type' => 'image/png'], (string) base64_decode(self::PNG)));

        $this->send($this->tap(Nav::to('tkv', 'ans', $ticket->id), chat: self::STAFF));
        $this->send($this->message(null, ['photo' => [['file_id' => 'photo-1', 'file_size' => 100, 'width' => 1, 'height' => 1]], 'caption' => 'این راهنما را ببینید.'], chat: self::STAFF));

        $answer = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertSame('این راهنما را ببینید.', $answer->body);
        self::assertTrue($answer->hasPicture(), 'the picture with the answer');
        self::assertStringContainsString('🖼 تصویر', $this->lastSaid());

        $this->send($this->tap(Nav::to('tkv', 'pic', $ticket->id, $answer->id, 1), chat: self::STAFF));
        self::assertContains('sendPhoto', $this->calls(), 'the picture as its own message');
        self::assertSame('🖼 تصویر فرستاده شد', $this->popup());
    }

    public function testAnAnswerTheShopRefusesKeepsTheQuestion(): void
    {
        $ticket = $this->ticket($this->ali);

        $this->send($this->tap(Nav::to('tkv', 'ans', $ticket->id), chat: self::STAFF));
        $this->send($this->message(str_repeat('ا', 4001), chat: self::STAFF));
        self::assertStringContainsString('⚠️', $this->lastSaid());
        self::assertStringContainsString('پیام حداکثر 4000 کاراکتر است.', $this->lastSaid(), 'the panel\'s own words on the limit');
        self::assertSame(1, TicketMessage::query()->where('ticket_id', $ticket->id)->count());

        $this->send($this->message('کوتاه‌تر: درست شد.', chat: self::STAFF));
        self::assertSame('کوتاه‌تر: درست شد.', TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->value('body'), 'the question still waited');
    }

    public function testClosingIsAskedFirstAndOpeningAgainWhenItDropsTheRating(): void
    {
        $ticket = $this->ticket($this->ali);

        $this->send($this->tap(Nav::to('tkv', 'cls', $ticket->id), chat: self::STAFF));
        self::assertStringContainsString('بسته می‌شود و مشتری خبردار می‌شود', $this->lastSaid());
        self::assertContains(Nav::to('tkv', 'clsd', $ticket->id), $this->buttons());
        self::assertSame(TicketStatus::Open, $ticket->refresh()->status, 'asked, nothing done yet');

        $this->send($this->tap(Nav::to('tkv', 'clsd', $ticket->id), chat: self::STAFF));
        self::assertSame(TicketStatus::Closed, $ticket->refresh()->status);
        self::assertSame('✅ تیکت بسته شد', $this->popup());
        self::assertSame('success', $this->button(Nav::to('tkv', 'rop', $ticket->id))['style'] ?? null, 'opening again green');
        self::assertNotContains(Nav::to('tkv', 'cls', $ticket->id), $this->buttons(), 'closed: no closing again');

        $this->send($this->tap(Nav::to('tkv', 'rop', $ticket->id), chat: self::STAFF));
        self::assertSame(TicketStatus::Open, $ticket->refresh()->status, 'not rated: opened at a tap');

        $ticket->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now(), 'rating' => 4])->save();
        $this->send($this->tap(Nav::to('tkv', 'rop', $ticket->id), chat: self::STAFF));
        self::assertStringContainsString('امتیاز مشتری را پاک می‌کند', $this->lastSaid());
        self::assertSame(TicketStatus::Closed, $ticket->refresh()->status);

        $this->send($this->tap(Nav::to('tkv', 'ropd', $ticket->id), chat: self::STAFF));
        self::assertSame([TicketStatus::Open, null], [$ticket->refresh()->status, $ticket->rating]);
    }

    public function testALongConversationIsReadAPageAtATime(): void
    {
        $ticket = $this->ticket($this->ali);
        $this->ticketMessages($ticket, 10);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'author' => TicketAuthor::Customer, 'body' => str_repeat('خ', 700), 'channel' => TicketChannel::Bot]);

        $this->send($this->tap(Nav::to('tkv', 'show', $ticket->id), chat: self::STAFF));
        $said = $this->lastSaid();
        self::assertStringContainsString("\n۱۲. 👤", $said, 'the latest first');
        self::assertStringNotContainsString("\n۷. ", $said);
        self::assertStringContainsString('صفحه ۱ از ۳', $said);
        $long = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertContains(Nav::to('tkv', 'm', $ticket->id, $long->id, 1), $this->buttons(), 'a long one cut, whole at a tap');
        self::assertContains(Nav::to('tkv', 'show', $ticket->id, 2), $this->buttons());

        $this->send($this->tap(Nav::to('tkv', 'show', $ticket->id, 3), chat: self::STAFF));
        self::assertStringContainsString("\n۱. 👤", $this->lastSaid());
        self::assertStringContainsString('سرویس من وصل نمی‌شود.', $this->lastSaid());

        $this->send($this->tap(Nav::to('tkv', 'm', $ticket->id, $long->id, 1), chat: self::STAFF));
        self::assertStringContainsString(str_repeat('خ', 700), $this->lastSaid());
        self::assertStringNotContainsString('بخش ۱ از', $this->lastSaid(), 'one part: no pages');
    }

    public function testAVeryLongMessageIsReadWholeAPartAtATime(): void
    {
        $ticket = $this->ticket($this->ali);
        // The panel's longest (4000), quotes that grow when escaped among the words.
        $body = trim(str_repeat('سلام "دنیا" & بازهم ', 199));
        $long = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'author' => TicketAuthor::Customer, 'body' => $body, 'channel' => TicketChannel::Bot]);
        $read = static fn(string $window): string => html_entity_decode(strip_tags((string) preg_replace('~^.*<blockquote>|</blockquote>.*$~su', '', $window)), ENT_QUOTES);

        $whole = '';
        for ($part = 1; $part <= 3; $part++) {
            $this->send($this->tap(Nav::to('tkv', 'm', $ticket->id, $long->id, $part), chat: self::STAFF));
            $window = $this->lastSaid();
            self::assertStringContainsString('بخش ' . Fmt::number($part) . ' از ۳', $window, 'some 6800 characters once escaped: three parts');
            self::assertStringNotContainsString("\n…", $window, 'nothing cut off');
            self::assertContains(Nav::to('tkv', 'show', $ticket->id, 1), $this->buttons(), 'back to its page');
            $whole .= $read($window);
        }
        self::assertContains(Nav::to('tkv', 'm', $ticket->id, $long->id, 2), $this->buttons(), 'the part before');
        self::assertSame(preg_replace('/\s+/u', '', $body), preg_replace('/\s+/u', '', $whole), 'every word, in order');
    }

    public function testAQuickAnswerGoesAtATapAndOnlyOnce(): void
    {
        $ticket = $this->ticket($this->ali);

        $this->send($this->tap(Nav::to('tkv', 'ans', $ticket->id), chat: self::STAFF));
        self::assertStringContainsString('پاسخ آماده', $this->lastSaid());
        self::assertStringContainsString(TicketScreen::QUICK[0][1], $this->lastSaid(), 'each listed whole before it is tapped');
        self::assertContains(Nav::to('tkv', 'qr', $ticket->id, 0), $this->buttons());

        $this->send($this->tap(Nav::to('tkv', 'qr', $ticket->id, 0), chat: self::STAFF));
        $answer = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        self::assertSame([TicketScreen::QUICK[0][1], TicketAuthor::Support, TicketChannel::Manage], [$answer->body, $answer->author, $answer->channel]);
        self::assertNotNull($answer->request_key, 'under the question\'s Idempotency-Key');
        self::assertSame(TicketStatus::Answered, $ticket->refresh()->status);
        self::assertSame('✅ پاسخ فرستاده شد', $this->popup());

        // Tapped again (a double tap, an old window): the question's key is spent, nothing goes twice.
        $this->send($this->tap(Nav::to('tkv', 'qr', $ticket->id, 0), chat: self::STAFF));
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
        self::assertStringContainsString('دیگر فرستاده نمی‌شود', (string) $this->popup());

        // A quick answer that is none.
        $this->send($this->tap(Nav::to('tkv', 'ans', $ticket->id), chat: self::STAFF));
        $this->send($this->tap(Nav::to('tkv', 'qr', $ticket->id, 9), chat: self::STAFF));
        self::assertSame(2, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    public function testOneCustomersTicketsAndASearch(): void
    {
        $this->ticket($this->ali, 'قطعی اتصال');
        $sara = $this->customer(['telegram_id' => 9001, 'first_name' => 'Sara']);
        $other = $this->ticket($sara, 'سوال درباره تمدید');

        $this->send($this->tap(Nav::to('tk', 'user', $sara->id), chat: self::STAFF));
        self::assertStringContainsString('فقط تیکت‌های مشتری', $this->lastSaid());
        self::assertSame([Nav::to('tkv', 'show', $other->id)], array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, 'a:tkv:'))));

        $this->send($this->tap(Nav::to('tk', 'all'), chat: self::STAFF));
        $this->send($this->tap(Nav::to('tk', 'search'), chat: self::STAFF));
        $this->send($this->message('تمدید', chat: self::STAFF));
        self::assertStringContainsString('🔎 «تمدید»', $this->lastSaid());
        self::assertSame([Nav::to('tkv', 'show', $other->id)], array_values(array_filter($this->buttons(), static fn(string $data): bool => str_starts_with($data, 'a:tkv:'))));
    }

    public function testTheOwnersAnswerIsThePanels(): void
    {
        $this->config(['admin.username' => 'root']);
        $ticket = $this->ticket($this->ali);
        $this->customer(['telegram_id' => 7001, 'first_name' => 'Amir']);
        $this->linkOwner(7001);

        $this->send($this->tap(Nav::to('tkv', 'ans', $ticket->id), chat: 7001));
        $this->send($this->message('از طرف مالک.', chat: 7001));

        self::assertSame(TicketChannel::Manage, TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail()->channel, 'written from the bot\'s management');
        self::assertStringContainsString("🎧 <b>پشتیبانی</b> · توسط \u{2068}root\u{2069} · مدیریت ربات", $this->lastSaid(), 'the owner reads their own login, isolated');
        self::assertSame(1, Ticket::query()->count());
    }

    /**
     * The window's button to `$data` now, as Telegram takes it; [] for none.
     *
     * @return array<string, mixed>
     */
    private function button(string $data): array
    {
        foreach (array_merge(...$this->inlineKeyboard($this->buttonsCall())) as $button) {
            if (($button['callback_data'] ?? null) === $data) {
                return $button;
            }
        }

        return [];
    }

    /**
     * The window's buttons now: the data of the last inline buttons the bot set.
     *
     * @return list<string>
     */
    private function buttons(): array
    {
        $call = $this->buttonsCall();

        return $this->inlineKeyboard($call) === [] ? [] : $this->callbacks($call);
    }
}
