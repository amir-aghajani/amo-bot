<?php

declare(strict_types=1);

namespace Tests\Feature\Store;

use App\Core\Exceptions\ValidationException;
use App\Core\Security\RateLimiter;
use App\Core\Support\FileCache;
use App\Modules\Notifications\Enums\NoticeType;
use App\Modules\Providers\Models\Server;
use App\Modules\Store\Api\TicketsController as StoreTicketsController;
use App\Modules\Store\Models\Website;
use App\Modules\Store\Services\CustomerTickets;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Ticket;
use App\Modules\Support\Models\TicketMessage;
use App\Modules\Support\Services\TicketAttachments;
use App\Modules\Support\Services\Tickets;
use App\Modules\Support\Tasks\PruneTicketsTask;
use App\Modules\Telegram\Api\BotApi;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Reports\TicketClose;
use App\Modules\Telegram\Reports\Topic;
use App\Modules\Users\Enums\PictureFolder;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\CustomerPictures;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Tests\HttpTestCase;

/**
 * A customer's support tickets on the shop's website — their own only, another's not there at all: opened with a
 * subject, a first message and, when they choose, the service it is about and a picture (JSON, or a form with its
 * `file`); written in under the ticket's hold — a closed one opens again —, read — and said read, support's words and
 * their notices read then —, closed, rated once closed. Every refusal at once, and a customer who opens, writes, rates or
 * sends pictures faster than a person waits (429: their pictures one budget with their receipts; the pictures they ask
 * for a hundred and twenty an hour); a ticket holds two hundred messages and twenty pictures the customer uploads; no
 * picture is taken while the host's disk has no room (503).
 * A closed ticket's uploaded pictures go a month after it closed, its messages saying they had one. What they do tells
 * nobody but the report group; support's answer reaches them — in Telegram, by email, in their feed.
 */
final class StoreTicketsTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Website $website;

    private User $customer;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->telegram();
        $this->website = $this->website();
        $this->customer = $this->customer(['username' => 'ali']);
        $this->server = $this->sellingServer('Berlin');
        $this->bearer($this->customerSession($this->customer));
    }

    public function testACustomerOpensATicketAboutTheirServiceAndOnlyTheReportGroupHearsOfIt(): void
    {
        $this->reportGroup();
        $service = $this->subscription($this->customer, $this->plan([], $this->server), $this->server, 'ali_1');

        $response = $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'قطعی اتصال', 'body' => "سلام\nسرویس وصل نمی‌شود.", 'subscription_id' => $service->id]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $ticket = $this->decode($response)['ticket'];
        $message = $ticket['messages'][0] ?? self::fail('No message.');
        self::assertSame(['قطعی اتصال', 'open', ['id' => $service->id, 'name' => 'ali_1'], false, null, null, '2026-10-08T12:00:00+00:00', null], [$ticket['subject'], $ticket['status'], $ticket['subscription'], $ticket['unread'], $ticket['rating'], $ticket['rating_note'], $ticket['last_message_at'], $ticket['closed_at']]);
        self::assertSame(['customer', "سلام\nسرویس وصل نمی‌شود.", null], [$message['author'], $message['body'], $message['attachment']], 'its line breaks kept');
        self::assertSame(TicketChannel::Web, TicketMessage::query()->sole()->channel);

        $report = ReportMessage::query()->sole();
        self::assertSame([Topic::Tickets, "ticket:{$ticket['id']}", TicketClose::buttons($ticket['id'])], [$report->topic, $report->ref, $report->keyboard]);
        self::assertStringContainsString("🎫 <b>تیکت جدید</b> · #{$ticket['id']}", $report->text);
        self::assertStringContainsString('🔖 سرویس: <code>ali_1</code>', $report->text);
        self::assertStringContainsString('📨 از وب‌سایت', $report->text);
        self::assertSame([], $this->telegram()->calls(), 'the customer is told nothing of their own doing');

        $list = $this->decode($this->get($this->storeApi($this->website, '/tickets')));
        self::assertSame([[$ticket['id'], 'open', false]], array_map(static fn(array $row): array => [$row['id'], $row['status'], $row['unread']], $list['tickets']));
        self::assertSame(1, $list['meta']['total']);
    }

    public function testATicketWithAPictureFromAFormItsPictureServedAndSentToTheGroupAsAPhoto(): void
    {
        $this->reportGroup();

        $response = $this->upload($this->storeApi($this->website, '/tickets'), 'file', "C:\\fakepath\\تصویر\u{202E}gpj.png", (string) base64_decode(self::PNG), ['subject' => 'خطای اتصال', 'body' => 'این خطا را می‌بینم.']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $ticket = $this->decode($response)['ticket'];
        $message = $ticket['messages'][0];
        self::assertSame(['name' => 'تصویرgpj.png', 'kept' => true], $message['attachment'], "the device's name, its last part, without what turns it around");
        $row = TicketMessage::query()->sole();
        self::assertMatchesRegularExpression("/^1-{$ticket['id']}-[0-9a-f]{16}\\.png$/", (string) $row->attachment_path, "a name of the shop's own");
        $kept = (string) file_get_contents($this->tickets() . '/' . $row->attachment_path);
        self::assertSame([1, 1, 'image/png'], self::imageOf($kept), 'the picture as the shop keeps it');

        $picture = $this->get($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages/{$message['id']}/attachment"));
        self::assertSame(200, $picture->getStatusCode());
        self::assertSame(['image/png', $kept], [$picture->getHeaderLine('Content-Type'), (string) $picture->getBody()]);

        self::assertSame("tickets/{$row->attachment_path}", ReportMessage::query()->sole()->photo_path, 'the report sends the picture by its bytes');
    }

    public function testSupportsAnswerIsUnreadUntilTheWebsiteSaysTheCustomerReadItAndReachesThemOnEveryDoor(): void
    {
        $ticket = $this->open();
        $other = $this->open('سوال دیگر');
        $tickets = $this->service(Tickets::class);
        $tickets->answer($this->ticketOf($ticket), $this->panelActor(), ['body' => 'مشکل برطرف شد.'], null);
        $tickets->answer($this->ticketOf($other), $this->panelActor(), ['body' => 'پاسخ تیکت دیگر'], null);

        $list = $this->decode($this->get($this->storeApi($this->website, '/tickets')));
        self::assertSame([['answered', true], ['answered', true]], array_map(static fn(array $row): array => [$row['status'], $row['unread']], $list['tickets']));
        self::assertSame(2, $list['meta']['unread'], "a badge's count, whatever the list shows");
        self::assertSame(2, $this->decode($this->get($this->storeApi($this->website, '/tickets?status=closed')))['meta']['unread'], 'whatever the tab');
        self::assertCount(2, $this->telegram()->sentTo((int) $this->customer->telegram_id), 'in Telegram');

        $shown = $this->decode($this->get($this->storeApi($this->website, "/tickets/{$ticket['id']}")))['ticket'];
        self::assertTrue($shown['unread'], 'reading it changes nothing');
        self::assertSame([['customer', 'سلام'], ['support', 'مشکل برطرف شد.']], array_map(static fn(array $message): array => [$message['author'], $message['body']], $shown['messages']));
        self::assertArrayNotHasKey('reviewer', $shown['messages'][1], 'who of support wrote it is never said');
        self::assertSame(2, $this->decode($this->get($this->storeApi($this->website, '/me')))['unread_notifications']);

        $read = $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/read"));
        self::assertSame(200, $read->getStatusCode(), (string) $read->getBody());
        self::assertFalse($this->decode($read)['ticket']['unread'], 'read now — the ticket as it stands');
        self::assertSame(1, $this->decode($this->get($this->storeApi($this->website, '/tickets')))['meta']['unread']);
        self::assertSame(1, $this->decode($this->get($this->storeApi($this->website, '/me')))['unread_notifications'], "its notices read with it — not the other ticket's");

        $feed = $this->decode($this->get($this->storeApi($this->website, '/notifications')))['notifications'];
        self::assertSame([
            [NoticeType::TicketAnswered->value, ['type' => 'ticket', 'id' => $other['id']], false],
            [NoticeType::TicketAnswered->value, ['type' => 'ticket', 'id' => $ticket['id']], true],
        ], array_map(static fn(array $notice): array => [$notice['type'], $notice['subject'], $notice['read']], $feed), 'in their feed, about the ticket');
        self::assertSame(404, $this->postJson($this->storeApi($this->website, '/tickets/999/read'))->getStatusCode());
    }

    public function testWritingOpensAClosedTicketAgainItsRatingLeftBehind(): void
    {
        $ticket = $this->open();
        $this->ticketOf($ticket)->forceFill(['status' => TicketStatus::Closed, 'closed_at' => now(), 'rating' => 2, 'rating_note' => 'دیر'])->save();
        Carbon::setTestNow('2026-10-08 13:00:00');

        $response = $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), ['body' => 'باز هم وصل نمی‌شود.']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $written = $this->decode($response)['ticket'];
        self::assertSame(['open', null, null, '2026-10-08T13:00:00+00:00', null], [$written['status'], $written['rating'], $written['rating_note'], $written['last_message_at'], $written['closed_at']]);
        self::assertCount(2, $written['messages']);
        self::assertNull($this->ticketOf($ticket)->closed_at);
        self::assertSame([], $this->telegram()->calls());

        $answered = $this->open('سوال دیگر');
        $this->ticketOf($answered)->forceFill(['status' => TicketStatus::Answered])->save();
        $response = $this->upload($this->storeApi($this->website, "/tickets/{$answered['id']}/messages"), 'file', 'screen.png', (string) base64_decode(self::PNG), ['body' => 'این هم تصویر']);
        self::assertSame(['open', ['name' => 'screen.png', 'kept' => true]], [$this->decode($response)['ticket']['status'], $this->decode($response)['ticket']['messages'][1]['attachment']], 'waiting on support again');
    }

    public function testClosingAndRatingIt(): void
    {
        $ticket = $this->open();
        $path = fn(string $to): string => $this->storeApi($this->website, "/tickets/{$ticket['id']}{$to}");

        $early = $this->postJson($path('/rating'), ['rating' => 5]);
        self::assertSame([422, Tickets::RATE_WHEN_CLOSED], [$early->getStatusCode(), $this->decode($early)['errors']['status'][0] ?? null], 'not while it is open');

        $closed = $this->postJson($path('/close'));
        self::assertSame(['closed', '2026-10-08T12:00:00+00:00'], [$this->decode($closed)['ticket']['status'], $this->decode($closed)['ticket']['closed_at']], 'and since when');
        self::assertNotNull($this->ticketOf($ticket)->closed_at);
        self::assertSame([], $this->telegram()->calls(), 'nobody told but the group');
        $again = $this->postJson($path('/close'));
        self::assertSame([422, Tickets::CLOSED], [$again->getStatusCode(), $this->decode($again)['errors']['status'][0] ?? null]);

        foreach ([0, 6, 'خوب'] as $rating) {
            $refused = $this->postJson($path('/rating'), ['rating' => $rating]);
            self::assertSame(['امتیاز باید عددی بین 1 تا 5 باشد.'], $this->decode($refused)['errors']['rating'] ?? null, (string) $rating);
        }
        $long = $this->postJson($path('/rating'), ['rating' => 4, 'note' => str_repeat('خ', Tickets::RATING_NOTE_MAX + 1)]);
        self::assertSame(['توضیح حداکثر 500 کاراکتر است.'], $this->decode($long)['errors']['note'] ?? null);

        $rated = $this->decode($this->postJson($path('/rating'), ['rating' => '۴', 'note' => 'سریع بود']))['ticket'];
        self::assertSame([4, 'سریع بود'], [$rated['rating'], $rated['rating_note']], 'Persian digits too');
        $rated = $this->decode($this->postJson($path('/rating'), ['rating' => 5]))['ticket'];
        self::assertSame([5, null], [$rated['rating'], $rated['rating_note']], 'a rating given before replaced');

        $closedOnes = $this->decode($this->get($this->storeApi($this->website, '/tickets?status=closed')))['tickets'];
        self::assertSame([$ticket['id']], array_column($closedOnes, 'id'));
        self::assertSame(['2026-10-08T12:00:00+00:00'], array_column($closedOnes, 'closed_at'), 'the list says since when too');
        self::assertSame([], $this->decode($this->get($this->storeApi($this->website, '/tickets?status=open')))['tickets']);
    }

    public function testWhatATicketTakesEveryRefusalAtOnce(): void
    {
        $theirs = $this->subscription($this->customer(['telegram_id' => 7272]), $this->plan([], $this->server), $this->server, 'reza_1');

        $response = $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'سل', 'body' => str_repeat('م', Tickets::BODY_MAX + 1), 'subscription_id' => $theirs->id]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([
            'subject' => ['موضوع حداقل 3 کاراکتر است.'],
            'body' => ['پیام حداکثر 4000 کاراکتر است.'],
            'subscription_id' => ['این سرویس پیدا نشد.'],
        ], $this->decode($response)['errors'], "another's service is none of theirs");

        $empty = $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => str_repeat('م', Tickets::SUBJECT_MAX + 1), 'body' => '   ']);
        self::assertSame(['subject' => ['موضوع حداکثر 120 کاراکتر است.'], 'body' => ['متن پیام را بنویسید.']], $this->decode($empty)['errors']);

        $notAPicture = $this->upload($this->storeApi($this->website, '/tickets'), 'file', 'note.png', 'just some words', ['subject' => 'قطعی اتصال', 'body' => 'سلام']);
        self::assertSame(['file' => ['تصویر باید JPG، PNG یا WebP باشد.']], $this->decode($notAPicture)['errors']);

        self::assertSame(0, Ticket::query()->count(), 'nothing opened');
        self::assertSame([], glob($this->tickets() . '/*') ?: [], 'nothing kept');
    }

    public function testAnotherCustomersTicketIsNotThere(): void
    {
        $theirs = $this->ticket($this->customer(['telegram_id' => 7272]));
        $message = TicketMessage::query()->where('ticket_id', $theirs->id)->sole();

        foreach ([
            ['GET', "/tickets/{$theirs->id}", null],
            ['POST', "/tickets/{$theirs->id}/messages", ['body' => 'سلام']],
            ['POST', "/tickets/{$theirs->id}/close", null],
            ['POST', "/tickets/{$theirs->id}/rating", ['rating' => 5]],
            ['GET', "/tickets/{$theirs->id}/messages/{$message->id}/attachment", null],
        ] as [$method, $path, $body]) {
            self::assertSame(404, $this->json($method, $this->storeApi($this->website, $path), $body)->getStatusCode(), "{$method} {$path}");
        }
        self::assertSame([], $this->decode($this->get($this->storeApi($this->website, '/tickets')))['tickets']);
        self::assertSame([TicketStatus::Open, 1], [$theirs->refresh()->status, TicketMessage::query()->where('ticket_id', $theirs->id)->count()]);

        $mine = $this->open();
        $response = $this->get($this->storeApi($this->website, "/tickets/{$mine['id']}/messages/{$mine['messages'][0]['id']}/attachment"));
        self::assertSame([404, 'این پیام تصویری ندارد.'], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testACustomerWhoOpensOrWritesFasterThanAPersonWaits(): void
    {
        foreach (range(1, Tickets::OPENS) as $n) {
            self::assertSame(201, $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => "سوال {$n}", 'body' => 'سلام'])->getStatusCode(), "ticket {$n}");
        }

        $refused = $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'یکی دیگر', 'body' => 'سلام']);
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('تیکت زیادی باز کرده‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.', $this->decode($refused)['message']);
        self::assertSame('3600', $refused->getHeaderLine('Retry-After'));
        self::assertSame(Tickets::OPENS, Ticket::query()->count());

        // Ten messages written with the tickets; twenty more in the ten minutes, then they wait.
        $ticket = Ticket::query()->latest('id')->firstOrFail();
        foreach (range(1, Tickets::MESSAGES - Tickets::OPENS) as $n) {
            self::assertSame(200, $this->postJson($this->storeApi($this->website, "/tickets/{$ticket->id}/messages"), ['body' => "پیام {$n}"])->getStatusCode(), "message {$n}");
        }
        $refused = $this->postJson($this->storeApi($this->website, "/tickets/{$ticket->id}/messages"), ['body' => 'باز هم']);
        self::assertSame([429, 'پیام زیادی فرستاده‌اید؛ ۱۰ دقیقه دیگر دوباره امتحان کنید.'], [$refused->getStatusCode(), $this->decode($refused)['message']]);

        Carbon::setTestNow(now()->addMinutes(11));
        self::assertSame(200, $this->postJson($this->storeApi($this->website, "/tickets/{$ticket->id}/messages"), ['body' => 'حالا'])->getStatusCode(), 'the window over');
        self::assertSame(429, $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'یکی دیگر', 'body' => 'سلام'])->getStatusCode(), 'the hour is not');

        // Another customer has windows of their own.
        $this->bearer($this->customerSession($this->customer(['telegram_id' => 7272])));
        self::assertSame(201, $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'سوال', 'body' => 'سلام'])->getStatusCode());
    }

    public function testAMessageIsWrittenUnderTheTicketsHoldOneWriteForWhereItStands(): void
    {
        $ticket = $this->open();
        Carbon::setTestNow(now()->addMinute());
        $writes = [];
        $this->whileListening(QueryExecuted::class, static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^update\s+"tickets"/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        }, fn(): ResponseInterface => $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), ['body' => 'یک نکته دیگر']));

        self::assertCount(1, $writes, 'waiting on support already: its latest moment, one write — not two moves that fail first');
    }

    public function testSupportClosingTheMomentTheCustomerWritesLeavesNoMessageUnseenOnAClosedTicket(): void
    {
        $ticket = $this->open();
        $closed = false;

        $response = $this->whileListening('eloquent.retrieved: ' . Ticket::class, static function (Ticket $read) use (&$closed): void {
            if (!$closed) {
                // Support closes it on a panel the moment the customer's request read it: what that read says is open.
                $closed = true;
                Ticket::query()->whereKey($read->id)->update(['status' => TicketStatus::Closed->value, 'closed_at' => now()]);
            }
        }, fn(): ResponseInterface => $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), ['body' => 'یک سوال دیگر']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->ticketOf($ticket);
        self::assertSame([TicketStatus::Open, null], [$row->status, $row->closed_at], 'the message read where the ticket stands under its hold: it opened it again, waiting on support');
    }

    public function testPicturesAreOneBudgetWithTheReceiptsTenAnHour(): void
    {
        // The review's: a picture with every message, and 89 of them were kept on the host in half an hour.
        $ticket = $this->open();
        $picture = fn(string $words): ResponseInterface => $this->upload($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), 'file', 'shot.png', (string) base64_decode(self::PNG), ['body' => $words]);
        foreach (range(1, CustomerPictures::UPLOADS) as $n) {
            self::assertSame(200, $picture("پیام {$n}")->getStatusCode(), "picture {$n}");
        }

        $refused = $picture('یکی دیگر');

        self::assertSame([429, '3600'], [$refused->getStatusCode(), $refused->getHeaderLine('Retry-After')]);
        self::assertSame('تصویر زیادی فرستاده‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.', $this->decode($refused)['message']);
        self::assertCount(CustomerPictures::UPLOADS, glob($this->tickets() . '/*') ?: [], 'nothing more kept');
        self::assertSame(200, $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), ['body' => 'بدون تصویر'])->getStatusCode(), 'words alone still go: the refused picture cost no message');

        // A receipt is a picture of the same budget.
        $payment = $this->cardPayment($this->topUpOrder($this->customer, '50000.00'), $this->cardMethod());
        $receipt = $this->upload($this->storeApi($this->website, "/payments/{$payment->id}/receipt"), 'file', 'receipt.png', (string) base64_decode(self::PNG));
        self::assertSame([429, 'تصویر زیادی فرستاده‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.'], [$receipt->getStatusCode(), $this->decode($receipt)['message']]);

        Carbon::setTestNow(now()->addHour());
        self::assertSame(200, $picture('حالا')->getStatusCode(), 'the hour over');
    }

    public function testAClosedTicketsUploadedPicturesGoAMonthAfterItClosedItsMessageSayingItHadOne(): void
    {
        $withPicture = fn(string $subject): array => $this->decode($this->upload($this->storeApi($this->website, '/tickets'), 'file', 'shot.png', (string) base64_decode(self::PNG), ['subject' => $subject, 'body' => 'این خطا را می‌بینم.']))['ticket'];
        $closed = $withPicture('قطعی اتصال');
        $open = $withPicture('سوال دیگر');
        $reopened = $withPicture('سوال سوم');
        $this->postJson($this->storeApi($this->website, "/tickets/{$closed['id']}/close"));
        $this->postJson($this->storeApi($this->website, "/tickets/{$reopened['id']}/close"));
        $files = fn(): int => count(glob($this->tickets() . '/*') ?: []);
        $task = $this->service(PruneTicketsTask::class);

        Carbon::setTestNow(now()->addDays(29));
        self::assertSame(200, $this->postJson($this->storeApi($this->website, "/tickets/{$reopened['id']}/messages"), ['body' => 'هنوز هست'])->getStatusCode());
        $task->run();
        self::assertSame(3, $files(), 'not a month yet');

        Carbon::setTestNow(now()->addDays(2));
        $task->run();

        self::assertSame(2, $files(), "the closed ticket's goes; the open one's and the one opened again keep theirs");
        $message = TicketMessage::query()->where('ticket_id', $closed['id'])->sole();
        self::assertSame([null, 'shot.png', 'این خطا را می‌بینم.'], [$message->attachment_path, $message->attachment_name, $message->body], 'its words kept, and its name: it had one');
        $shown = $this->decode($this->get($this->storeApi($this->website, "/tickets/{$closed['id']}")))['ticket']['messages'][0];
        self::assertSame(['name' => 'shot.png', 'kept' => false], $shown['attachment'], 'said so up front');
        $gone = $this->get($this->storeApi($this->website, "/tickets/{$closed['id']}/messages/{$message->id}/attachment"));
        self::assertSame([404, 'فایل این تصویر دیگر روی سرور نیست.'], [$gone->getStatusCode(), $this->decode($gone)['message']]);
        self::assertSame(['name' => 'shot.png', 'kept' => true], $this->decode($this->get($this->storeApi($this->website, "/tickets/{$open['id']}")))['ticket']['messages'][0]['attachment']);

        $this->loginAsAdmin();
        self::assertSame(['name' => 'shot.png', 'kept' => false], $this->decode($this->get("/api/admin/tickets/{$closed['id']}"))['ticket']['messages'][0]['attachment'], 'the panels too');
    }

    public function testRatingsAreMessagesOfTheirWindowAndTheGroupHearsOnlyAFirstOrAChangedOne(): void
    {
        $this->reportGroup();
        $ticket = $this->open();
        $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/close"));
        $reports = static fn(): int => ReportMessage::query()->where('topic', Topic::Tickets->value)->count();
        $rate = fn(array $rating): ResponseInterface => $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/rating"), $rating);
        $before = $reports();

        self::assertSame([200, 200], [$rate(['rating' => 4])->getStatusCode(), $rate(['rating' => 4])->getStatusCode()]);
        self::assertSame($before + 1, $reports(), 'a first rating is heard; the same again tells nothing');
        $rate(['rating' => 4, 'note' => 'سریع بود']);
        $rate(['rating' => 5, 'note' => 'سریع بود']);
        self::assertSame($before + 3, $reports(), 'one that changed is heard');

        // The review's: sixty ratings in a row each queued a report — each is a message of the customer's window now.
        $statuses = [];
        foreach (range(1, 60) as $n) {
            $statuses[] = $rate(['rating' => 1 + $n % 5])->getStatusCode();
        }

        self::assertCount(Tickets::MESSAGES - 5, array_filter($statuses, static fn(int $status): bool => $status === 200), "thirty messages in ten minutes — the ticket's first and four ratings among them");
        self::assertSame(429, $statuses[59]);
        self::assertSame($before + 3 + Tickets::MESSAGES - 5, $reports(), 'what was refused told nobody');
    }

    public function testATicketHoldsTwoHundredMessagesThenANewTicketIsTheWayOn(): void
    {
        $ticket = $this->open();
        $this->ticketMessages($this->ticketOf($ticket), Tickets::MESSAGES_MAX - 1);

        $full = $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), ['body' => 'یکی دیگر']);

        self::assertSame([422, [Tickets::FULL]], [$full->getStatusCode(), $this->decode($full)['errors']['status'] ?? null]);
        try {
            $this->service(Tickets::class)->answer($this->ticketOf($ticket), $this->panelActor(), ['body' => 'پاسخ'], null);
            self::fail('Support wrote in a full ticket.');
        } catch (ValidationException $e) {
            self::assertSame(['status' => [Tickets::FULL]], $e->errors(), "support's answer too");
        }
        self::assertSame(Tickets::MESSAGES_MAX, TicketMessage::query()->where('ticket_id', $ticket['id'])->count());
        self::assertSame(201, $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'ادامه سوال', 'body' => 'سلام'])->getStatusCode(), 'a new ticket is the way on');
    }

    public function testTheWebsiteAsksForAHundredAndTwentyPicturesAnHour(): void
    {
        $ticket = $this->decode($this->upload($this->storeApi($this->website, '/tickets'), 'file', 'shot.png', (string) base64_decode(self::PNG), ['subject' => 'قطعی اتصال', 'body' => 'این خطا']))['ticket'];
        $address = $this->storeApi($this->website, "/tickets/{$ticket['id']}/messages/{$ticket['messages'][0]['id']}/attachment");
        foreach (range(1, CustomerTickets::PICTURES) as $n) {
            self::assertSame(200, $this->get($address)->getStatusCode(), "picture {$n}");
        }

        $refused = $this->get($address);

        self::assertSame([429, '3600', 'تصویر زیادی خواسته‌اید؛ ۶۰ دقیقه دیگر دوباره امتحان کنید.'], [$refused->getStatusCode(), $refused->getHeaderLine('Retry-After'), $this->decode($refused)['message']]);
        Carbon::setTestNow(now()->addHour());
        self::assertSame(200, $this->get($address)->getStatusCode(), 'the hour over');
    }

    public function testATicketTakesTwentyPicturesTheCustomerUploads(): void
    {
        $ticket = $this->decode($this->upload($this->storeApi($this->website, '/tickets'), 'file', 'shot.png', (string) base64_decode(self::PNG), ['subject' => 'قطعی اتصال', 'body' => 'این خطا']))['ticket'];
        $write = fn(): ResponseInterface => $this->upload($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), 'file', 'shot.png', (string) base64_decode(self::PNG), ['body' => 'و این یکی']);
        for ($picture = 2; $picture <= Tickets::PICTURES_MAX; $picture++) {
            // The customer's budget of uploads is an hour's: each picture an hour after the last.
            Carbon::setTestNow(now()->addHour());
            self::assertSame(200, $write()->getStatusCode(), "picture {$picture}");
        }
        Carbon::setTestNow(now()->addHour());

        $refused = $write();

        self::assertSame([422, ['file' => [sprintf(Tickets::PICTURES_FULL, '۲۰')]]], [$refused->getStatusCode(), $this->decode($refused)['errors']]);
        self::assertSame(Tickets::PICTURES_MAX, TicketMessage::query()->where('ticket_id', $ticket['id'])->count(), 'nothing written, nothing kept');
        self::assertCount(Tickets::PICTURES_MAX, glob($this->tickets() . '/*') ?: []);
        self::assertSame(200, $this->postJson($this->storeApi($this->website, "/tickets/{$ticket['id']}/messages"), ['body' => 'بدون تصویر'])->getStatusCode(), 'words alone still go');
    }

    public function testNoPictureIsTakenWhileTheHostsDiskHasNoRoomLeft(): void
    {
        // Every byte the disk has, kept free: as a disk under the room the shop keeps free is.
        $full = new CustomerPictures($this->service(BotApi::class), [
            PictureFolder::Receipts->value => (string) $this->app()->container()->get('receipts.path'),
            PictureFolder::Tickets->value => $this->tickets(),
        ], $this->service(LoggerInterface::class), PHP_INT_MAX, $this->service(RateLimiter::class), $this->service(FileCache::class));
        $this->swap(CustomerPictures::class, $full, TicketAttachments::class, Tickets::class, StoreTicketsController::class);
        $logs = $this->logs();

        $refused = $this->upload($this->storeApi($this->website, '/tickets'), 'file', 'shot.png', (string) base64_decode(self::PNG), ['subject' => 'قطعی اتصال', 'body' => 'این خطا']);

        self::assertSame([503, 'فضای ذخیره سرور پر شده و فعلا تصویری پذیرفته نمی‌شود؛ کمی بعد دوباره امتحان کنید.'], [$refused->getStatusCode(), $this->decode($refused)['message']]);
        self::assertSame('', $refused->getHeaderLine('Retry-After'), 'the room comes back when the owner makes it');
        self::assertSame([0, []], [Ticket::query()->count(), glob($this->tickets() . '/*') ?: []]);
        self::assertTrue($logs->hasWarningThatContains('An upload was refused'), 'the owner hears it');
        self::assertSame(201, $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => 'قطعی اتصال', 'body' => 'بدون تصویر', 'subscription_id' => null])->getStatusCode(), 'words alone still go — about no service');
    }

    /**
     * The customer opens a ticket from the website: what it answered.
     *
     * @return array<string, mixed>
     */
    private function open(string $subject = 'قطعی اتصال'): array
    {
        $response = $this->postJson($this->storeApi($this->website, '/tickets'), ['subject' => $subject, 'body' => 'سلام']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->decode($response)['ticket'];
    }

    /** @param array<string, mixed> $ticket */
    private function ticketOf(array $ticket): Ticket
    {
        return Ticket::query()->with(['user', 'subscription'])->findOrFail($ticket['id']);
    }

    /** Where the tickets' pictures are kept: the run's own folder. */
    private function tickets(): string
    {
        return (string) $this->app()->container()->get('tickets.path');
    }
}
