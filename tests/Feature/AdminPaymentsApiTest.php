<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Handlers\PurchaseHandler;
use App\Modules\Telegram\Handlers\ReceiptHandler;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use App\Support\Input;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Validation;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeProvider;
use Tests\HttpTestCase;
use Tests\Support\FakeTelegram;

/**
 * The payments screen: the list with its tabs, search and the shop's days — and what it took in —, the receipt served
 * from Telegram, and the review — approving
 * delivers the order and tells the customer (a refused receipt too, once support sees the money), rejecting tells them
 * why, cancelling drops the open order — its other open payments with it — and says so only then, a reminder nudges with its buttons, a retry speaks only
 * when the delivery worked. Every word about a decision is a bare reply to the receipt; only the reminder carries
 * buttons. An approval that meets its order closed in the same moment is refused and changes nothing. (Refunds:
 * PaymentRefundsTest.)
 */
final class AdminPaymentsApiTest extends HttpTestCase
{
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->loginAsAdmin();

        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->card = $this->cardMethod();
    }

    public function testTheListCarriesTheReviewQueueCountAndFilters(): void
    {
        $sara = $this->customer(['telegram_id' => 1002, 'first_name' => 'Sara']);
        // An order nobody started paying: the orders and the payments are numbered apart from here on.
        $this->topUpOrder($sara, '10000.00');
        $waiting = $this->topUpAwaitingReview('50000.00', note: 'واریز از ملت');
        $server = $this->sellingServer();
        $bought = $this->buy($sara, $this->plan(on: $server), $server);
        $paid = $bought->payments()->sole();

        $data = $this->decode($this->get('/api/admin/payments'));
        self::assertSame([$paid->id, $waiting->id], array_column($data['payments'], 'id'), 'newest first');
        self::assertSame(1, $data['meta']['awaiting_review']);
        self::assertSame(2, $data['meta']['total']);

        $row = $data['payments'][1];
        self::assertSame('awaiting_review', $row['status']);
        self::assertSame($this->card->label, $row['method']);
        self::assertSame('manual', $row['kind'], 'how the driver settles, from its descriptor');
        self::assertSame(['id' => $this->ali->id, 'name' => 'Ali', 'username' => 'ali_r', 'telegram_id' => 1001, 'email' => null], $row['user']);
        self::assertSame(OrderType::WalletTopUp->value, $row['order']['type'], 'the panel words the type itself');
        self::assertSame(['name' => null, 'note' => 'واریز از ملت', 'sent_at' => $row['receipt']['sent_at']], $row['receipt']);
        self::assertNotNull($row['receipt']['sent_at']);
        self::assertNull($row['reviewer']);
        self::assertSame('6037 •••• •••• 1119 · AmoBot', $row['summary'], 'the card the customer was told to pay to, and whose it is');
        self::assertSame(['approve' => true, 'reject' => true, 'cancel' => true, 'remind' => false, 'retry' => false, 'refund' => false], $row['actions']);

        $wallet = $data['payments'][0];
        self::assertSame(['instant', null, null], [$wallet['kind'], $wallet['summary'], $wallet['receipt']], 'the wallet has no card to show, and no receipt');
        self::assertSame(['approve' => false, 'reject' => false, 'cancel' => false, 'remind' => false, 'retry' => false, 'refund' => true], $wallet['actions']);

        $ids = fn(string $query): array => array_column($this->decode($this->get("/api/admin/payments?{$query}"))['payments'], 'id');
        self::assertSame([$waiting->id], $ids('status=awaiting_review'));
        self::assertSame([$paid->id], $ids('search=Sara'));
        self::assertSame([$waiting->id], $ids('search=@ali'));
        self::assertSame([$waiting->id], $ids('search=1001'), 'by Telegram id');
        self::assertSame([$waiting->id], $ids('search=' . urlencode("#{$waiting->id}")), 'by its number');
        self::assertSame([$paid->id], $ids("search={$bought->id}"), "a bare number: its own, or its order's");
        self::assertNotSame($paid->id, $bought->id, 'numbered apart');
        self::assertSame([], $ids('search=' . urlencode("#{$bought->id}")), '«#n» is the payment numbered n alone — how another screen links to one');
    }

    public function testOnePaymentIsReadAsTheListShowsIt(): void
    {
        $waiting = $this->topUpAwaitingReview('50000.00');

        $listed = $this->decode($this->get('/api/admin/payments'))['payments'][0];
        self::assertSame(['payment' => $listed], $this->decode($this->get("/api/admin/payments/{$waiting->id}")));
        self::assertSame(404, $this->get('/api/admin/payments/999999')->getStatusCode());
    }

    public function testTheListNarrowsToTheShopsDaysAndSaysWhatItTookIn(): void
    {
        LocalTime::use('Asia/Tehran');
        try {
            // 20:00 UTC on the 5th is 23:30 on the 5th in Tehran; 21:00 UTC is already 00:30 on the 6th there.
            Carbon::setTestNow(Carbon::parse('2026-10-05 20:00:00', 'UTC'));
            $fifth = $this->cardPayment($this->topUpOrder($this->ali, '10000.00'), $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
            Carbon::setTestNow(Carbon::parse('2026-10-05 21:00:00', 'UTC'));
            $paid = $this->cardPayment($this->topUpOrder($this->ali, '30000.00'), $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
            $waiting = $this->topUpAwaitingReview('50000.00');
            Carbon::setTestNow(Carbon::parse('2026-10-06 21:00:00', 'UTC'));
            $seventh = $this->cardPayment($this->topUpOrder($this->ali, '7000.00'), $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
            Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));
            $list = fn(string $query): array => $this->decode($this->get("/api/admin/payments?{$query}"));
            $ids = static fn(array $data): array => array_column($data['payments'], 'id');

            $sixth = $list('from=2026-10-06&to=2026-10-06');
            self::assertSame([$waiting->id, $paid->id], $ids($sixth), "the shop's day, not UTC's");
            self::assertSame([1, '30000.00', 1], [$sixth['meta']['paid'], $sixth['meta']['paid_amount'], $sixth['meta']['awaiting_review']], 'what came in: the paid ones alone; the queue whatever the days');

            self::assertSame([$seventh->id, $waiting->id, $paid->id], $ids($list('from=2026-10-06')), 'from a day on');
            self::assertSame([$fifth->id], $ids($list('to=2026-10-05')), 'up to a day');
            $this->unchecked(); // bounds no panel sends: its date picker gives dates
            $all = $list('from=yesterday&to=2026-13-01');
            self::assertCount(4, $all['payments'], 'a bound that is no date is no bound');
            self::assertSame([3, '47000.00'], [$all['meta']['paid'], $all['meta']['paid_amount']]);
        } finally {
            LocalTime::use('UTC');
        }
    }

    public function testTheReceiptIsFetchedFromTelegramAndServedInline(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00');
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 32);
        $this->telegram()->reply(['file_id' => 'receipt-1', 'file_path' => 'photos/file_1.jpg']);
        $this->telegram()->raw(new Response(200, ['Content-Type' => 'image/jpeg'], $jpeg));

        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('inline', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('private, max-age=300', $response->getHeaderLine('Cache-Control'), 'kept a while by the reviewer\'s browser alone');
        self::assertSame($jpeg, (string) $response->getBody());
        self::assertSame('receipt-1', $this->telegram()->params(0)['file_id']);
        self::assertStringEndsWith('/file/bot' . FakeTelegram::TOKEN . '/photos/file_1.jpg', (string) $this->telegram()->history[1]['request']->getUri());

        // Read again a moment later: the bytes kept a while, Telegram not asked — even out of reach.
        $this->telegram()->fail(502, 'Bad Gateway');
        $again = $this->get("/api/admin/payments/{$payment->id}/receipt");
        self::assertSame([200, $jpeg], [$again->getStatusCode(), (string) $again->getBody()]);

        // Once that while is over, Telegram out of reach: the receipt is still there — ask again later.
        $this->fileCacheExpired();
        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");
        self::assertSame(502, $response->getStatusCode());
        self::assertSame('تلگرام در دسترس نبود؛ چند لحظه بعد دوباره امتحان کنید.', $this->decode($response)['message']);

        // Telegram no longer hands it over.
        $this->telegram()->fail(400, 'Bad Request: wrong file_id or the file is temporarily unavailable');
        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");
        self::assertSame([404, 'فایل این رسید دیگر در تلگرام نیست.'], [$response->getStatusCode(), $this->decode($response)['message']]);

        // No receipt at all: the transfer's picture never came.
        $unsent = $this->cardPayment($this->topUpOrder($this->ali, '20000.00'), $this->card);
        $response = $this->get("/api/admin/payments/{$unsent->id}/receipt");
        self::assertSame([404, 'این پرداخت رسیدی ندارد.'], [$response->getStatusCode(), $this->decode($response)['message']]);
    }

    public function testAReceiptThePanelDoesNotShowIsAFileToSaveUnderASafeName(): void
    {
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $this->service(PaymentService::class)->submitReceipt($payment, 'receipt-1', null, "رسید\"\r\nX-Evil: 1.svg", self::RECEIPT_MESSAGE);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->telegram()->reply(['file_id' => 'receipt-1', 'file_path' => 'documents/file_2.svg']);
        $this->telegram()->raw(new Response(200, ['Content-Type' => 'image/svg+xml'], $svg));

        $response = $this->get("/api/admin/payments/{$payment->id}/receipt");

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'), 'never rendered on the panel\'s origin');
        $disposition = $response->getHeaderLine('Content-Disposition');
        self::assertStringStartsWith('attachment; filename="', $disposition);
        self::assertStringNotContainsString("\r", $disposition);
        self::assertStringNotContainsString("\n", $disposition);
        self::assertStringContainsString("filename*=UTF-8''", $disposition, 'the real name, encoded');
        self::assertSame($svg, (string) $response->getBody());
    }

    public function testApprovingSettlesThePaymentFulfilsTheOrderAndTellsTheCustomer(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00');

        $response = $this->postJson("/api/admin/payments/{$payment->id}/approve");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['payment'];
        self::assertSame('paid', $row['status']);
        self::assertSame(self::ADMIN_USERNAME, $row['reviewer']);
        self::assertFalse($row['auto_approved']);
        self::assertSame('fulfilled', $row['order']['status']);
        self::assertNotNull($row['receipt']['sent_at'], 'the receipt details survive the verdict');
        self::assertSame('50000.00', $this->ali->balance(), 'the top-up landed');

        self::assertSame([self::charged('50000.00')], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'sent as a reply to the receipt');
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0), 'and nothing attached');

        $again = $this->postJson("/api/admin/payments/{$payment->id}/approve");
        self::assertSame(422, $again->getStatusCode(), 'reviewed once');
        self::assertSame('50000.00', $this->ali->balance(), 'and credited once');
    }

    public function testRejectingKeepsTheOrderOpenAndTellsTheCustomerWhy(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00', note: 'واریز کردم');

        $response = $this->postJson("/api/admin/payments/{$payment->id}/reject", ['note' => 'مبلغ رسید با سفارش نمی‌خواند']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['payment'];
        self::assertSame('failed', $row['status']);
        self::assertSame('مبلغ رسید با سفارش نمی‌خواند', $row['description'], 'the reason');
        self::assertSame('واریز کردم', $row['receipt']['note'], "the customer's own note is untouched");
        self::assertSame(self::ADMIN_USERNAME, $row['reviewer']);
        self::assertSame('pending', $row['order']['status'], 'the order can still be paid');
        self::assertSame(['approve' => true, 'reject' => false, 'cancel' => true, 'remind' => true, 'retry' => false, 'refund' => false], $row['actions'], 'support may still find the money, drop it or nudge');
        self::assertSame('0.00', $this->ali->balance());

        self::assertSame([self::text(BotText::ReceiptRejected, [
            'order' => $payment->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'مبلغ رسید با سفارش نمی‌خواند']),
        ])], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'sent as a reply to the receipt');
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0), 'and nothing attached');

        // A nudge about a rejected payment offers paying again, not the receipt that was just refused.
        $this->telegram()->reset();
        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/remind")->getStatusCode());
        self::assertSame([self::text(BotText::PaymentReminderRejected, ['order' => $payment->order_id, 'amount' => Money::format('50000')])], $this->telegram()->sentTo(1001));
        self::assertSame([[self::button(BotText::PayAgain, PurchaseHandler::reopenCallback($payment->order_id))]], $this->keyboard(0));

        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/reject", ['note' => 'دوباره'])->getStatusCode(), 'a rejected payment cannot be rejected again');
        self::assertSame(404, $this->postJson('/api/admin/payments/999999/approve')->getStatusCode());
    }

    public function testARefusedReceiptIsApprovedAfterAllWhenSupportFindsTheMoney(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00');
        $this->postJson("/api/admin/payments/{$payment->id}/reject", ['note' => 'ناخوانا']);
        $this->telegram()->reset();

        $response = $this->postJson("/api/admin/payments/{$payment->id}/approve");

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['payment'];
        self::assertSame(['paid', null, 'fulfilled'], [$row['status'], $row['description'], $row['order']['status']], 'the word on the refusal goes with it');
        self::assertSame('50000.00', $this->ali->balance());
        self::assertSame([self::charged('50000.00')], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0));
    }

    public function testTheAdminsNoteIsCappedAndOptional(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00');

        $long = $this->postJson("/api/admin/payments/{$payment->id}/reject", ['note' => str_repeat('x', Input::NOTE_MAX + 1)]);
        self::assertSame(422, $long->getStatusCode());
        self::assertSame(['note' => [Validation::tooLong('توضیح', Input::NOTE_MAX)]], $this->decode($long)['errors']);
        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'refused before anything moved');

        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/reject", [])->getStatusCode());
        self::assertSame([self::text(BotText::ReceiptRejected, ['order' => $payment->order_id, 'note' => ''])], $this->telegram()->sentTo(1001), 'no note, no note line');
    }

    public function testAnUnpaidCardPaymentCanBeApprovedByHandRemindedOrCancelled(): void
    {
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        self::assertSame(['approve' => true, 'reject' => false, 'cancel' => true, 'remind' => true, 'retry' => false, 'refund' => false], $this->service(PaymentActions::class)->allowed($payment));

        // A nudge: send the receipt, or pay again.
        $reminded = $this->postJson("/api/admin/payments/{$payment->id}/remind");
        self::assertSame(200, $reminded->getStatusCode(), (string) $reminded->getBody());
        self::assertSame(['told', $payment->id], [$this->decode($reminded)['delivery'], $this->decode($reminded)['payment']['id']], 'the answer says the customer was told');
        self::assertSame([self::text(BotText::PaymentReminder, ['order' => $payment->order_id, 'amount' => Money::format('50000')])], $this->telegram()->sentTo(1001));
        self::assertSame([
            [self::button(BotText::SendReceipt, ReceiptHandler::stateFor($payment))],
            [self::button(BotText::PayAgain, PurchaseHandler::reopenCallback($payment->order_id))],
        ], $this->keyboard(0));

        // The admin saw the transfer some other way: approve without a receipt.
        $this->telegram()->reset();
        $approved = $this->postJson("/api/admin/payments/{$payment->id}/approve");
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame('paid', $this->decode($approved)['payment']['status']);
        self::assertNull($this->telegram()->replyTarget(0), 'no receipt to reply to: a plain message');
        self::assertSame('50000.00', $this->ali->balance());
        self::assertSame([self::charged('50000.00')], $this->telegram()->sentTo(1001));

        // Another one, dropped with a word to the customer.
        $second = $this->cardPayment($this->topUpOrder($this->ali, '20000.00'), $this->card);
        $this->telegram()->reset();
        $cancelled = $this->postJson("/api/admin/payments/{$second->id}/cancel", ['note' => 'سفارش تکراری بود']);
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        $row = $this->decode($cancelled)['payment'];
        self::assertSame(['cancelled', 'cancelled', self::ADMIN_USERNAME], [$row['status'], $row['order']['status'], $row['reviewer']]);
        self::assertSame([self::text(BotText::OrderCancelledByAdmin, [
            'order' => $second->order_id,
            'note' => self::text(BotText::AdminNote, ['comment' => 'سفارش تکراری بود']),
        ])], $this->telegram()->sentTo(1001));
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0), 'no keyboard on a verdict');
        self::assertSame(['approve' => false, 'reject' => false, 'cancel' => false, 'remind' => false, 'retry' => false, 'refund' => false], $row['actions']);

        self::assertSame(422, $this->postJson("/api/admin/payments/{$second->id}/remind")->getStatusCode(), 'nothing to remind about');
    }

    public function testAReminderSaysWhenItCouldNotBeSentAndWhy(): void
    {
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);
        $delivery = fn(): string => $this->decode($this->postJson("/api/admin/payments/{$payment->id}/remind"))['delivery'];

        $this->telegram()->fail(502, 'Bad Gateway');
        self::assertSame('unreachable', $delivery(), 'Telegram out of reach: try again later');
        $this->telegram()->fail(400, 'Bad Request: message is too long');
        self::assertSame('refused', $delivery());
        $this->telegram()->fail(403, 'Forbidden: bot was blocked by the user');
        self::assertSame('turned_away', $delivery());
        self::assertTrue($this->ali->refresh()->bot_blocked, 'learned');

        $this->telegram()->reset();
        self::assertSame('turned_away', $delivery(), 'known: not even tried');
        self::assertSame([], $this->telegram()->calls());
    }

    public function testCancellingAPaymentSentWithAReceiptRepliesToItAndWithoutANoteSaysOnlyThat(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00');

        self::assertSame(200, $this->postJson("/api/admin/payments/{$payment->id}/cancel", [])->getStatusCode());

        self::assertSame([self::text(BotText::OrderCancelledByAdmin, ['order' => $payment->order_id, 'note' => ''])], $this->telegram()->sentTo(1001), 'no note, no note line');
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'a reply to the receipt that was sent');
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0));
    }

    public function testCancellingAPaymentDropsTheOtherOpenPaymentsOfItsOrderWithIt(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $other = $this->cardPayment($order, $this->cardMethod('کارت دوم'));
        $payment = $this->cardPayment($order, $this->card);

        $row = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/cancel", ['note' => 'سفارش تکراری بود']))['payment'];

        self::assertSame(['cancelled', 'cancelled'], [$row['status'], $row['order']['status']]);
        $other->refresh();
        self::assertSame([PaymentStatus::Cancelled, 'سفارش تکراری بود', self::ADMIN_USERNAME], [$other->status, $other->note, $other->reviewer], 'an order dropped leaves none of its payments open — as the orders screen drops one');
        self::assertCount(1, $this->telegram()->sentTo(1001), 'the customer told once');
    }

    /**
     * The customer began paying with one card, gave up, and paid with another, whose receipt waits for support: «لغو
     * پرداخت» on the first tidies it away alone — the order and the receipt in review, which may be the money, stay
     * support's to decide, and the customer hears nothing.
     */
    public function testCancellingAPaymentWhileAnotherOfItsOrderWaitsOnItsReceiptDropsItAlone(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $stale = $this->cardPayment($order, $this->cardMethod('کارت اول'));
        $inReview = $this->receipt($this->cardPayment($order, $this->card));
        $this->telegram()->reset();

        $response = $this->postJson("/api/admin/payments/{$stale->id}/cancel", ['note' => 'پرداخت ناتمام']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['payment'];
        self::assertSame(['cancelled', 'pending'], [$row['status'], $row['order']['status']]);
        self::assertSame(PaymentStatus::AwaitingReview, $inReview->refresh()->status, "the receipt in review stays support's");
        self::assertSame([], $this->telegram()->calls(), 'nothing of theirs dropped: tidied away quietly');

        self::assertSame(200, $this->postJson("/api/admin/payments/{$inReview->id}/approve")->getStatusCode());
        self::assertSame('50000.00', $this->ali->balance(), 'the receipt approved, the order delivered');
    }

    public function testAPaymentLeftBehindByAnOrderPaidAnotherWayGoesQuietly(): void
    {
        $rejected = $this->topUpAwaitingReview('50000.00');
        $this->postJson("/api/admin/payments/{$rejected->id}/reject", ['note' => 'ناخوانا']);
        $unfinished = $this->cardPayment($rejected->order, $this->cardMethod('کارت دوم'));
        // The customer paid the same order again and that one went through.
        $again = $this->cardPayment($rejected->order, $this->card);
        $this->telegram()->reset();
        $this->postJson("/api/admin/payments/{$again->id}/approve");
        self::assertSame(OrderStatus::Fulfilled, $rejected->order->refresh()->status);

        // The others went with the order's payment — no word about them to the customer, who heard of the top-up —, the
        // rejected one keeping the reason its customer was told.
        $row = $this->decode($this->get("/api/admin/payments/{$rejected->id}"))['payment'];
        self::assertSame(['cancelled', 'ناخوانا'], [$row['status'], $row['description']]);
        $row = $this->decode($this->get("/api/admin/payments/{$unfinished->id}"))['payment'];
        self::assertSame(['cancelled', PaymentService::NOTE_PAID_OTHERWISE], [$row['status'], $row['description']]);
        self::assertSame([self::charged('50000.00')], $this->telegram()->sentTo(1001));
        self::assertSame(422, $this->postJson("/api/admin/payments/{$rejected->id}/cancel", [])->getStatusCode(), 'nothing left to cancel');
    }

    public function testCancellingAReceiptOfAnOrderPaidAnotherWayIsQuiet(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $inReview = $this->receipt($this->cardPayment($order, $this->card));
        // The customer paid the same order with another card too, and support accepted that one first.
        $this->paidByCard($order, $this->cardMethod('کارت دوم'));
        $this->telegram()->reset();

        $response = $this->postJson("/api/admin/payments/{$inReview->id}/cancel", ['note' => 'دو بار پرداخت شد']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $row = $this->decode($response)['payment'];
        self::assertSame(['cancelled', 'fulfilled'], [$row['status'], $row['order']['status']]);
        self::assertSame([], $this->telegram()->calls(), 'it dropped nothing of theirs: tidied away quietly');
    }

    public function testARefusalSaysWhatThePaymentCameTo(): void
    {
        $refusal = function (Payment $payment, string $action): string {
            $response = $this->postJson("/api/admin/payments/{$payment->id}/{$action}", in_array($action, ['reject', 'cancel', 'refund'], true) ? [] : null);
            self::assertSame(422, $response->getStatusCode(), $action);

            return $this->decode($response)['errors']['status'][0];
        };

        // Decided elsewhere a moment ago — another tab, the report group, the review window —: said as it was decided.
        $approved = $this->topUpAwaitingReview('50000.00');
        $this->service(PaymentService::class)->approve($approved, '@boss');
        self::assertSame('این پرداخت تایید شده است.', $refusal($approved, 'approve'));
        self::assertSame('این پرداخت تایید شده است.', $refusal($approved, 'reject'));
        self::assertSame('این پرداخت انجام شده است و لغو نمی‌شود؛ برای برگرداندن مبلغ، بازپرداختش کنید.', $refusal($approved, 'cancel'));
        self::assertSame('سفارش این پرداخت تحویل شده است.', $refusal($approved, 'retry'));
        $this->postJson("/api/admin/payments/{$approved->id}/refund", []);
        self::assertSame('این پرداخت بازپرداخت شده است.', $refusal($approved, 'refund'));

        $cancelled = $this->cardPayment($this->topUpOrder($this->ali, '20000.00'), $this->card);
        $this->postJson("/api/admin/payments/{$cancelled->id}/cancel", []);
        self::assertSame('این پرداخت لغو شده است.', $refusal($cancelled, 'approve'));
        self::assertSame('این پرداخت لغو شده است.', $refusal($cancelled, 'remind'));

        $waiting = $this->cardPayment($this->topUpOrder($this->ali, '30000.00'), $this->card);
        self::assertSame('رسید این پرداخت هنوز نرسیده است.', $refusal($waiting, 'reject'));
        self::assertSame('این پرداخت انجام نشده است.', $refusal($waiting, 'refund'));
        self::assertSame('رسید این پرداخت رسیده و در انتظار بررسی است.', $refusal($this->receipt($waiting), 'remind'));

        // Its order paid with another card meanwhile: the receipt still in review is not what paid it.
        $this->paidByCard($waiting->order, $this->cardMethod('کارت دوم'));
        self::assertSame('سفارش این پرداخت با پرداخت دیگری پرداخت شده است.', $refusal($waiting, 'approve'));
    }

    public function testAnApprovalThatMeetsItsOrderClosedInTheSameMomentIsRefusedAndChangesNothing(): void
    {
        $payment = $this->topUpAwaitingReview('50000.00');

        $response = $this->whileTheOrderClosesOnceRead(fn() => $this->postJson("/api/admin/payments/{$payment->id}/approve"));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['status' => [(new OrderNotPayableException($payment->order))->getMessage()]], $this->decode($response)['errors']);
        self::assertSame([PaymentStatus::AwaitingReview, null], [$payment->refresh()->status, $payment->reviewer], 'the approval unwound');
        self::assertSame('0.00', $this->ali->balance());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testARetryTellsTheCustomerOnlyWhenTheDeliveryWorked(): void
    {
        $this->withoutQr();
        $server = $this->sellingServer('Berlin', ['is_active' => false]);
        $plan = $this->plan(['price' => '50000.00'], $server);
        $payment = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $plan, $server), $this->card));

        // The server is off: the payment is accepted, the delivery fails, the customer is told once.
        $approved = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/approve"))['payment'];
        self::assertSame(['paid', 'failed'], [$approved['status'], $approved['order']['status']]);
        self::assertNotNull($approved['order']['notes'], 'the admin sees why');
        self::assertTrue($approved['actions']['retry']);
        self::assertSame([self::text(BotText::PayProvisionFailed)], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0));

        // Still off: the retry fails again — the admin's problem, not news for the customer.
        $this->telegram()->reset();
        $retried = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/retry"))['payment'];
        self::assertSame('failed', $retried['order']['status']);
        self::assertSame([], $this->telegram()->calls(), 'no second "it failed"');

        // Back on: delivered, and the service goes out as a reply to the receipt.
        $server->forceFill(['is_active' => true])->save();
        $retried = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/retry"))['payment'];
        self::assertSame('fulfilled', $retried['order']['status']);
        self::assertCount(1, FakeProvider::$created);
        self::assertSame([self::text(BotText::PaySuccess, $this->service(ServiceCard::class)->values(Subscription::query()->sole()))], $this->telegram()->sentTo(1001));
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0));
        self::assertArrayNotHasKey('reply_markup', $this->telegram()->params(0));
    }

    public function testADeliveryWhoseProcessDiedCanBeRetriedOnceItsClaimIsStale(): void
    {
        // The money is in, and the process delivering it died holding its claim: the customer has nothing.
        $order = $this->topUpOrder($this->ali, '50000.00', ['status' => OrderStatus::Processing]);
        $payment = $this->cardPayment($order, $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);

        $row = $this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment'];
        self::assertSame('processing', $row['order']['status']);
        self::assertFalse($row['actions']['retry'], 'a live claim may still be delivering');
        self::assertSame(422, $this->postJson("/api/admin/payments/{$payment->id}/retry")->getStatusCode());

        Carbon::setTestNow(now()->addMinutes(OrderService::STALE_PROCESSING_MINUTES + 1));
        self::assertTrue($this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['actions']['retry'], 'stale: nobody is delivering it');
        $retried = $this->decode($this->postJson("/api/admin/payments/{$payment->id}/retry"))['payment'];
        self::assertSame('fulfilled', $retried['order']['status']);
        self::assertSame('50000.00', $this->ali->balance(), 'delivered by the retry, once');
        self::assertSame([self::charged('50000.00')], $this->telegram()->sentTo(1001));
    }

    public function testADeliveryBrokenByAFaultOfTheShopsOwnFailsTheOrderAndIsAnError(): void
    {
        // A traffic order of a customer on an agency level whose shop was never opened: no bot to add the traffic to.
        $order = $this->service(OrderService::class)->openTraffic($this->customer(['telegram_id' => 7007, 'agency_level_id' => $this->agencyLevel()->id]), 20);
        $payment = $this->receipt($this->cardPayment($order, $this->card));
        $logs = $this->logs();

        $response = $this->postJson("/api/admin/payments/{$payment->id}/approve");

        self::assertSame(500, $response->getStatusCode());
        self::assertTrue($logs->hasErrorThatContains("The agent of traffic order #{$order->id} has no bot."), 'the fault is in the log, in full');
        self::assertSame([OrderStatus::Failed, OrderService::DELIVERY_BROKEN], [$order->refresh()->status, $order->notes], 'the order says so, in the shop\'s words, for the retry');
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'the money is in');
        self::assertTrue($this->decode($this->get("/api/admin/payments/{$payment->id}"))['payment']['actions']['retry']);
    }

    /** Ali's wallet top-up paid by card, its receipt (a photo) waiting for review. */
    private function topUpAwaitingReview(string $amount, ?string $note = null): Payment
    {
        return $this->receipt($this->cardPayment($this->topUpOrder($this->ali, $amount), $this->card), $note);
    }

    /** What the customer reads once their wallet was charged up to `$balance`. */
    private static function charged(string $balance): string
    {
        return self::text(BotText::WalletCharged, ['balance' => Messages::balance($balance)]);
    }

    /** @return array{text: string, callback_data: string} A button of the bot's, as Telegram is handed it. */
    private static function button(BotText $label, string $data): array
    {
        return ['text' => self::text($label), 'callback_data' => $data];
    }

    /** @return list<list<array<string, mixed>>> The inline buttons the n-th Bot API call put under its message, row by row. */
    private function keyboard(int $call): array
    {
        return $this->telegram()->markup($call)['inline_keyboard'] ?? [];
    }
}
