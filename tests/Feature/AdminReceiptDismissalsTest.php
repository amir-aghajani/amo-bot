<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\StaffGrant;
use App\Modules\Bots\CurrentBot;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\Receipts;
use App\Modules\Store\Http\StaffMiddleware;
use App\Modules\Telegram\Models\ReportMessage;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\HttpTestCase;

/**
 * Receipts waiting for review dismissed in bulk (POST /payments/dismiss): those named — or every one waiting, the oldest
 * first — each rejected as «رد کردن» rejects one (the payment failed with support's note, its order still payable, the
 * customer told, the receipt's report losing its buttons), an uploaded picture deleted, one line for them all in the
 * report group; a payment no longer waiting is skipped, another shop's too. «All» is held to the receipts that had come
 * when it began (`until`). A website admin needs the `payments` grant.
 */
final class AdminReceiptDismissalsTest extends HttpTestCase
{
    /** A PNG of one pixel: a picture by its bytes. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $ali;
    private User $sara;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->ali = $this->customer(['telegram_id' => 1001, 'username' => 'ali_r']);
        $this->sara = $this->customer(['telegram_id' => 1002, 'username' => 'sara']);
        $this->card = $this->cardMethod();
    }

    public function testTheReceiptsNamedAreRejectedTheirUploadsDeletedTheirCustomersToldAndTheGroupGetsOneLine(): void
    {
        $this->loginAsAdmin();
        $this->reportGroup();
        $inBot = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $uploaded = $this->uploaded($this->sara);
        $paid = $this->paidByCard($this->topUpOrder($this->ali, '20000.00'), $this->card);
        $file = $this->receiptsFolder() . '/' . $uploaded->receipt_path;
        self::assertFileExists($file);
        $this->telegram()->reset();

        $response = $this->postJson('/api/admin/payments/dismiss', ['ids' => [$inBot->id, $uploaded->id, $paid->id, $inBot->id], 'note' => 'رسید تکراری بود']);

        self::assertSame(['dismissed' => 2, 'skipped' => 1, 'left' => 0, 'until' => null], $this->decode($response));
        foreach ([$inBot, $uploaded] as $payment) {
            $payment->refresh();
            self::assertSame([PaymentStatus::Failed, 'رسید تکراری بود', self::ADMIN_USERNAME, OrderStatus::Pending], [$payment->status, $payment->note, $payment->reviewer, $payment->order->status], 'rejected as one is, its order still payable');
            self::assertSame(1, ReportMessage::query()->where('reply_ref', "receipt:{$payment->id}")->where('clears_buttons', true)->count(), "its report's buttons go");
        }
        self::assertSame(PaymentStatus::Paid, $paid->refresh()->status, 'skipped: no receipt waits');
        self::assertFileDoesNotExist($file, 'the uploaded picture is deleted');
        $note = self::text(BotText::AdminNote, ['comment' => 'رسید تکراری بود']);
        self::assertSame([self::text(BotText::ReceiptRejected, ['order' => $inBot->order_id, 'note' => $note])], $this->telegram()->sentTo(1001));
        self::assertSame([self::text(BotText::ReceiptRejected, ['order' => $uploaded->order_id, 'note' => $note])], $this->telegram()->sentTo(1002));
        $line = ReportMessage::query()->where('text', 'like', '%رسید در انتظار بررسی یکجا رد شد%')->sole();
        self::assertSame("🗑 <b>۲ رسید در انتظار بررسی یکجا رد شد</b>\n🎧 توسط پشتیبانی\n📝 توضیح پشتیبانی: رسید تکراری بود", $line->text, "the owner's login reaches no group");
    }

    public function testAllTakesEveryReceiptWaitingTheOldestFirst(): void
    {
        $this->loginAsAdmin();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $first = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $second = $this->receipt($this->cardPayment($this->topUpOrder($this->sara, '60000.00'), $this->card));
        $open = $this->cardPayment($this->topUpOrder($this->sara, '70000.00'), $this->card);

        $response = $this->postJson('/api/admin/payments/dismiss', ['all' => true]);

        self::assertSame(['dismissed' => 2, 'skipped' => 0, 'left' => 0, 'until' => '2026-10-14T10:00:00+00:00'], $this->decode($response));
        self::assertSame([PaymentStatus::Failed, PaymentStatus::Failed, PaymentStatus::Pending], [$first->refresh()->status, $second->refresh()->status, $open->refresh()->status], 'a payment without a receipt is left alone');
        self::assertSame([null, null], [$first->note, $second->note]);
        self::assertSame([self::text(BotText::ReceiptRejected, ['order' => $first->order_id, 'note' => ''])], $this->telegram()->sentTo(1001), 'no note, no note line');
    }

    public function testAllIsHeldToTheReceiptsThatHadComeWhenItBegan(): void
    {
        $this->loginAsAdmin();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $first = $this->decode($this->postJson('/api/admin/payments/dismiss', ['all' => true]));

        // Sent while the run goes on: the next round, sending back the first answer's moment, leaves it to be seen.
        Carbon::setTestNow('2026-10-14 10:00:05');
        $later = $this->receipt($this->cardPayment($this->topUpOrder($this->sara, '60000.00'), $this->card));
        $next = $this->postJson('/api/admin/payments/dismiss', ['all' => true, 'until' => $first['until']]);

        self::assertSame(['dismissed' => 0, 'skipped' => 0, 'left' => 0, 'until' => '2026-10-14T10:00:00+00:00'], $this->decode($next));
        self::assertSame(PaymentStatus::AwaitingReview, $later->refresh()->status);

        // A moment yet to come is now; one that is no moment is refused.
        $ahead = $this->postJson('/api/admin/payments/dismiss', ['all' => true, 'until' => '2030-01-01T00:00:00+00:00']);
        self::assertSame(['dismissed' => 1, 'skipped' => 0, 'left' => 0, 'until' => '2026-10-14T10:00:05+00:00'], $this->decode($ahead));
        $refused = $this->unchecked()->postJson('/api/admin/payments/dismiss', ['all' => true, 'until' => 'yesterday']);
        self::assertSame(['until' => ['زمان (until) را همان‌طور که پاسخ قبلی داده است بفرستید.']], $this->decode($refused)['errors']);
    }

    public function testEachShopDismissesItsOwnReceiptsAlone(): void
    {
        $this->loginAsAdmin();
        $bot = $this->agentBot();
        $theirs = CurrentBot::run($bot, fn(): Payment => $this->receipt($this->cardPayment($this->topUpOrder($this->customer(['telegram_id' => 3001]), '40000.00'), $this->cardMethod())));
        $ours = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));

        $named = $this->postJson('/api/admin/payments/dismiss', ['ids' => [$theirs->id]]);
        self::assertSame(['dismissed' => 0, 'skipped' => 1, 'left' => 1, 'until' => null], $this->decode($named), "another shop's receipt is none of this one's");
        $all = $this->decode($this->postJson('/api/admin/payments/dismiss', ['all' => true]));
        self::assertSame([1, 0, 0], [$all['dismissed'], $all['skipped'], $all['left']]);
        self::assertSame(PaymentStatus::Failed, $ours->refresh()->status);
        self::assertSame(PaymentStatus::AwaitingReview, CurrentBot::run($bot, static fn(): PaymentStatus => $theirs->refresh()->status), 'the main shop\'s «all» is its own');

        $this->openShop($bot);
        self::assertSame(1, $this->decode($this->postJson('/api/admin/payments/dismiss', ['all' => true]))['dismissed'], 'the agent\'s shop open: its own');
    }

    public function testARequestThatNamesNothingOrTooMuchIsRefused(): void
    {
        $this->loginAsAdmin();
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));

        foreach ([[], ['ids' => [$payment->id], 'all' => true], ['all' => false]] as $body) {
            $refused = $this->postJson('/api/admin/payments/dismiss', $body);
            self::assertSame(422, $refused->getStatusCode(), (string) json_encode($body));
            self::assertSame(['ids' => ['رسیدهایی را که رد می‌شوند انتخاب کنید.']], $this->decode($refused)['errors']);
        }
        $tooMany = $this->unchecked()->postJson('/api/admin/payments/dismiss', ['ids' => range(1, 101)]);
        self::assertSame(['ids' => ['هر بار حداکثر ۱۰۰ رسید یکجا رد می‌شود.']], $this->decode($tooMany)['errors']);
        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'nothing moved');
    }

    public function testAWebsiteAdminNeedsThePaymentsGrant(): void
    {
        $website = $this->website();
        $this->loginAsStaff($website, $this->customer(['telegram_id' => 7001, 'username' => 'boss_site']));
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $dismiss = fn() => $this->postJson($this->storeApi($website, '/admin/payments/dismiss'), ['ids' => [$payment->id]]);

        $refused = $dismiss();
        self::assertSame([403, StaffMiddleware::NOT_GRANTED], [$refused->getStatusCode(), $this->decode($refused)['message']]);

        $website->forceFill(['staff_grants' => [StaffGrant::Payments->value]])->save();
        self::assertSame(['dismissed' => 1, 'skipped' => 0, 'left' => 0, 'until' => null], $this->decode($dismiss()));
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status);
    }

    /** A receipt uploaded from the website for a top-up of `$customer`'s: its picture kept in the receipts folder. */
    private function uploaded(User $customer): Payment
    {
        $payment = $this->cardPayment($this->topUpOrder($customer, '30000.00'), $this->card);
        $path = $this->service(Receipts::class)->keep($payment, (string) base64_decode(self::PNG), 'png');
        self::assertTrue($this->service(PaymentService::class)->submitUpload($payment, $path, null, 'receipt.png'));

        return $payment->refresh();
    }

    /** Where the uploads are kept: the run's own folder. */
    private function receiptsFolder(): string
    {
        return (string) $this->app()->container()->get('receipts.path');
    }
}
