<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\PrincipalKind;
use App\Modules\Bots\CurrentBot;
use App\Modules\Support\DTO\Attachment;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\Tickets;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * The tickets screen (both panels): every ticket of the shop the latest activity first — by status, by customer, searched
 * —, the open ones counted (the queue), one ticket with its conversation (who of support wrote each word — the owner's
 * login «پشتیبانی» in an agent's shop —, where each was written), and what support does: answer (JSON, or a form with a
 * picture) — the customer told, the report group under the ticket —, close — the customer told —, reopen. Each shop its
 * own.
 */
final class AdminTicketsApiTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $ali;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->loginAsAdmin();
        $this->ali = $this->customer(['username' => 'ali', 'first_name' => 'Ali']);
    }

    public function testTheListIsEveryTicketTheLatestActivityFirstWithTheQueueCounted(): void
    {
        $sara = $this->webCustomer();
        $server = $this->sellingServer();
        $service = $this->subscription($this->ali, $this->plan([], $server), $server, 'ali_1');
        $oldest = $this->ticket($this->ali, 'قطعی اتصال', overrides: ['last_message_at' => now()->subDays(2), 'subscription_id' => $service->id]);
        $answered = $this->ticket($sara, 'پرداخت ناموفق', overrides: ['status' => TicketStatus::Answered, 'last_message_at' => now()->subDay()]);
        $newest = $this->ticket($this->ali, 'سوال درباره تمدید', overrides: ['last_message_at' => now()]);
        $closed = $this->ticket($sara, 'بسته شد', overrides: ['status' => TicketStatus::Closed, 'closed_at' => now(), 'rating' => 4, 'last_message_at' => now()->subHours(5)]);
        $list = fn(string $query = ''): array => $this->decode($this->get('/api/admin/tickets' . ($query === '' ? '' : "?{$query}")));
        $ids = static fn(array $page): array => array_column($page['tickets'], 'id');

        $all = $list();
        self::assertSame([$newest->id, $closed->id, $answered->id, $oldest->id], $ids($all), 'the latest activity first');
        self::assertSame(['total' => 4, 'open' => 2], ['total' => $all['meta']['total'], 'open' => $all['meta']['open']]);
        $row = $all['tickets'][3];
        self::assertSame(['قطعی اتصال', 'open', ['id' => $service->id, 'name' => 'ali_1'], 1, null], [$row['subject'], $row['status'], $row['subscription'], $row['messages_count'], $row['rating']]);
        self::assertSame(['id' => $this->ali->id, 'name' => 'Ali', 'username' => 'ali', 'telegram_id' => self::TELEGRAM_ID, 'email' => null], $row['customer'], 'the customer as every screen points at them');
        self::assertSame([4, '2026-10-08T12:00:00+00:00'], [$all['tickets'][1]['rating'], $all['tickets'][1]['closed_at']]);
        self::assertSame([null, null, null], [$all['tickets'][0]['closed_at'], $all['tickets'][2]['closed_at'], $row['closed_at']], 'none but the closed one\'s');

        self::assertSame([$newest->id, $oldest->id], $ids($list('status=open')));
        self::assertSame([$answered->id], $ids($list('status=answered')));
        self::assertSame(2, $list('status=closed')['meta']['open'], 'the queue whatever the tab');
        self::assertSame([$newest->id, $oldest->id], $ids($list("user={$this->ali->id}")), 'one customer\'s (their page links here)');

        self::assertSame([$newest->id, $oldest->id], $ids($list('search=ali')), 'by the customer');
        self::assertSame([$closed->id, $answered->id], $ids($list('search=' . rawurlencode('sara@'))), 'by an email');
        self::assertSame([$answered->id], $ids($list('search=' . rawurlencode('پرداخت'))), 'by the subject');
        self::assertSame([$oldest->id], $ids($list('search=' . rawurlencode("#{$oldest->id}"))), 'the ticket alone');
        self::assertContains($oldest->id, $ids($list("search={$oldest->id}")), 'a bare number finds it among the rest');
    }

    public function testATicketWithItsConversationWhoOfSupportWroteAndWhere(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال', 'سلام');
        $tickets = $this->service(Tickets::class);
        $tickets->answer($ticket, $this->groupAdminActor(), ['body' => 'از گروه پاسخ داده شد'], Attachment::telegram('photo-1'));
        $tickets->answer($ticket->refresh(), $this->panelActor(), ['body' => 'از پنل'], null);

        $detail = $this->decode($this->get("/api/admin/tickets/{$ticket->id}"))['ticket'];

        self::assertSame(['answered', 3, null], [$detail['status'], $detail['messages_count'], $detail['rating_note']]);
        self::assertSame([
            ['customer', null, 'web', null],
            ['support', '@boss', 'group', ['name' => 'photo.jpg', 'kept' => true]],
            ['support', self::ADMIN_USERNAME, 'panel', null],
        ], array_map(static fn(array $message): array => [$message['author'], $message['reviewer'], $message['channel'], $message['attachment']], $detail['messages']));

        // An agent reads their shop's: the owner's login is «پشتیبانی» there.
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, function () use ($tickets): Ticket {
            $ticket = $this->ticket($this->customer(['telegram_id' => 7272]));
            $tickets->answer($ticket, $this->panelActor(), ['body' => 'پاسخ مالک'], null);
            $tickets->answer($ticket->refresh(), $this->panelActor('@agent_shop_bot', PrincipalKind::Agent), ['body' => 'پاسخ نماینده'], null);

            return $ticket;
        });
        $this->loginAsAgent($bot);
        $messages = $this->decode($this->get("/api/agent/tickets/{$theirs->id}"))['ticket']['messages'];
        self::assertSame([null, 'پشتیبانی', '@agent_shop_bot'], array_column($messages, 'reviewer'));
    }

    public function testSupportAnswersAndTheCustomerIsToldAndTheGroupHearsItUnderTheTicket(): void
    {
        $this->reportGroup();
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');

        $response = $this->postJson("/api/admin/tickets/{$ticket->id}/messages", ['body' => "سلام\nمشکل برطرف شد."]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $detail = $this->decode($response)['ticket'];
        self::assertSame(['answered', 'support', self::ADMIN_USERNAME, 'panel'], [$detail['status'], $detail['messages'][1]['author'], $detail['messages'][1]['reviewer'], $detail['messages'][1]['channel']]);
        self::assertTrue($ticket->refresh()->customer_unread, 'unread by the customer');
        self::assertSame([self::text(BotText::TicketAnswered, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال', 'answer' => "سلام\nمشکل برطرف شد.", 'picture' => ''])], $this->telegram()->sentTo(self::TELEGRAM_ID));

        $report = ReportMessage::query()->sole();
        self::assertSame(["ticket:{$ticket->id}", "ticket:{$ticket->id}", true], [$report->ref, $report->reply_ref, $report->clears_buttons], 'under the ticket, its close button handed down');
        self::assertStringContainsString("↩️ <b>پاسخ پشتیبانی</b> · تیکت #{$ticket->id} · از پنل", $report->text);
        self::assertStringNotContainsString(self::ADMIN_USERNAME, $report->text, "the owner's login is no group's to read");

        // With a picture, as a form.
        $this->telegram()->reset();
        $response = $this->upload("/api/admin/tickets/{$ticket->id}/messages", 'file', 'guide.png', (string) base64_decode(self::PNG), ['body' => 'این راهنما را ببینید.']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $message = $this->decode($response)['ticket']['messages'][2];
        self::assertSame(['name' => 'guide.png', 'kept' => true], $message['attachment']);
        self::assertStringContainsString(trim(self::text(BotText::TicketAnswerPicture)), $this->telegram()->sentTo(self::TELEGRAM_ID)[0] ?? '', 'the picture said so');
        self::assertSame('sendPhoto', $this->telegram()->calls()[0] ?? null, 'and sent with it, the words its caption');
        $picture = $this->get("/api/admin/tickets/{$ticket->id}/messages/{$message['id']}/attachment");
        self::assertSame(['image/png', [1, 1, 'image/png']], [$picture->getHeaderLine('Content-Type'), self::imageOf((string) $picture->getBody())]);
        self::assertStringStartsWith('tickets/', (string) ReportMessage::query()->latest('id')->firstOrFail()->photo_path);

        $empty = $this->postJson("/api/admin/tickets/{$ticket->id}/messages", ['body' => ' ']);
        self::assertSame([422, ['متن پیام را بنویسید.']], [$empty->getStatusCode(), $this->decode($empty)['errors']['body'] ?? null]);
        self::assertSame(3, TicketMessage::query()->where('ticket_id', $ticket->id)->count());
    }

    public function testClosingTellsTheCustomerAndAClosedTicketOpensAgainOnlyOnce(): void
    {
        $ticket = $this->ticket($this->ali, 'قطعی اتصال');

        $closed = $this->postJson("/api/admin/tickets/{$ticket->id}/close");

        self::assertSame(['closed', '2026-10-08T12:00:00+00:00'], [$this->decode($closed)['ticket']['status'], $this->decode($closed)['ticket']['closed_at']], 'and since when');
        self::assertSame([self::text(BotText::TicketClosed, ['ticket' => (string) $ticket->id, 'subject' => 'قطعی اتصال'])], $this->telegram()->sentTo(self::TELEGRAM_ID));
        $again = $this->postJson("/api/admin/tickets/{$ticket->id}/close");
        self::assertSame([422, Tickets::CLOSED], [$again->getStatusCode(), $this->decode($again)['message']]);

        $ticket->refresh()->forceFill(['rating' => 2, 'rating_note' => 'دیر'])->save();
        $reopened = $this->decode($this->postJson("/api/admin/tickets/{$ticket->id}/reopen"))['ticket'];
        self::assertSame(['open', null, null, null], [$reopened['status'], $reopened['rating'], $reopened['rating_note'], $reopened['closed_at']], 'waiting on support, its end and its rating left behind');
        self::assertNull($ticket->refresh()->closed_at);
        $again = $this->postJson("/api/admin/tickets/{$ticket->id}/reopen");
        self::assertSame([422, Tickets::NOT_CLOSED], [$again->getStatusCode(), $this->decode($again)['message']]);

        // Answering a closed ticket opens it again — waiting on the customer.
        $this->postJson("/api/admin/tickets/{$ticket->id}/close");
        $answered = $this->decode($this->postJson("/api/admin/tickets/{$ticket->id}/messages", ['body' => 'یک نکته دیگر']))['ticket'];
        self::assertSame('answered', $answered['status']);
    }

    public function testAnAgentsShopIsItsOwn(): void
    {
        $mine = $this->ticket($this->ali);
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Ticket => $this->ticket($this->customer(['telegram_id' => 7272])));

        self::assertSame([$mine->id], array_column($this->decode($this->get('/api/admin/tickets'))['tickets'], 'id'));
        self::assertSame(404, $this->get("/api/admin/tickets/{$theirs->id}")->getStatusCode(), "another shop's");

        $this->loginAsAgent($bot);
        self::assertSame([$theirs->id], array_column($this->decode($this->get('/api/agent/tickets'))['tickets'], 'id'));
        self::assertSame(404, $this->postJson("/api/agent/tickets/{$mine->id}/close")->getStatusCode());
        self::assertSame('answered', $this->decode($this->postJson("/api/agent/tickets/{$theirs->id}/messages", ['body' => 'سلام']))['ticket']['status']);
        self::assertSame(TicketStatus::Open, $mine->refresh()->status);
    }

    public function testAPictureSentInTelegramIsFetchedFromIt(): void
    {
        $ticket = $this->ticket($this->ali);
        $this->service(Tickets::class)->answer($ticket, $this->groupAdminActor(), ['body' => 'تصویر'], Attachment::telegram('photo-1'));
        $message = TicketMessage::query()->where('ticket_id', $ticket->id)->latest('id')->firstOrFail();
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 32);
        $this->telegram()->reset();
        $this->telegram()->reply(['file_id' => 'photo-1', 'file_path' => 'photos/file_1.jpg']);
        $this->telegram()->raw(new Response(200, ['Content-Type' => 'image/jpeg'], $jpeg));
        $address = "/api/admin/tickets/{$ticket->id}/messages/{$message->id}/attachment";

        $picture = $this->get($address);

        self::assertSame(['image/jpeg', $jpeg], [$picture->getHeaderLine('Content-Type'), (string) $picture->getBody()]);
        self::assertSame('photo-1', $this->telegram()->params(0)['file_id']);

        $this->telegram()->fail(502, 'Bad Gateway');
        self::assertSame(200, $this->get($address)->getStatusCode(), 'read again a moment later: the bytes kept a while, Telegram not asked');
        $this->fileCacheExpired();
        self::assertSame(502, $this->get($address)->getStatusCode(), 'Telegram out of reach: the picture is still there');
        $this->telegram()->fail(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
        $gone = $this->get($address);
        self::assertSame([404, 'این تصویر دیگر در تلگرام نیست.'], [$gone->getStatusCode(), $this->decode($gone)['message']]);

        $first = TicketMessage::query()->where('ticket_id', $ticket->id)->oldest('id')->firstOrFail();
        $none = $this->get("/api/admin/tickets/{$ticket->id}/messages/{$first->id}/attachment");
        self::assertSame([404, 'این پیام تصویری ندارد.'], [$none->getStatusCode(), $this->decode($none)['message']]);
        self::assertSame(404, $this->get("/api/admin/tickets/{$ticket->id}/messages/999/attachment")->getStatusCode());
    }
}
