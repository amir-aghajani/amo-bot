<?php

declare(strict_types=1);

namespace Tests\Feature\BotAdmin;

use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\DepositStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Deposit;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\Checkout;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Users\Enums\UserRole;
use App\Modules\Users\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Tests\BotTestCase;

/**
 * «💳 پرداخت‌ها» and «📩 واریزهای پیامکی» in the bot's management (Telegram\Admin\Sections\Sales): a receipt reviewed
 * from its picture and approved, refunded only with the grant, rejected with a reason typed for the customer; a payment
 * reminded and cancelled; receipts dismissed together only with the grant; and the bank SMS with what came of each —
 * each through the panel's own routes.
 */
final class SalesPaymentsTest extends BotTestCase
{
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config(['telegram.username' => 'amo_shop_bot']);
        $this->ali = $this->customer(['first_name' => 'Ali']);
        $this->card = $this->cardMethod();
    }

    public function testAReceiptIsApprovedFromItsPictureAndRefundedOnlyWithTheGrant(): void
    {
        $this->staff();
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card), 'واریز از ملت');
        $this->receiptPicture();

        $this->send($this->tap(Nav::to('py'), chat: 7100));
        self::assertContains('🧾 در انتظار بررسی (۱)', $this->buttonLabels(0), 'the review tab counts the receipts');
        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7100));
        self::assertContains(Nav::to('pyv', 'show', $payment->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id), chat: 7100));
        self::assertContains('editMessageMedia', $this->calls(), 'the screen is the receipt, its facts the caption');
        $caption = implode("\n", $this->said());
        self::assertStringContainsString('پرداخت ' . Fmt::id($payment->id), $caption);
        self::assertStringContainsString('واریز از ملت', $caption, 'the customer\'s note');
        self::assertContains(Nav::to('pyv', 'okn', $payment->id), $this->callbacks($this->windowCall()), 'in the review queue: approved, then the next receipt');

        $this->send($this->tap(Nav::to('pyv', 'ok', $payment->id), chat: 7100));
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'approved through the panel\'s route');
        self::assertStringContainsString('کیف پول شارژ شد', (string) $this->popup());
        self::assertNotContains(Nav::to('pyv', 'rf', $payment->id), $this->callbacks($this->windowCall()), 'no refund without the grant');

        $this->send($this->tap(Nav::to('pyv', 'rfg', $payment->id), chat: 7100));
        self::assertSame(BotAdminDoor::NOT_GRANTED, $this->popup(), 'the door refuses it');
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);

        $this->botSettings('bot_admins', ['bot_admins_grant_refunds' => true]);
        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id), chat: 7100));
        self::assertContains(Nav::to('pyv', 'rf', $payment->id), $this->callbacks($this->windowCall()));
        $this->send($this->tap(Nav::to('pyv', 'rf', $payment->id), chat: 7100));
        self::assertStringContainsString('بیرون از فروشگاه', $this->said()[0], 'a top-up\'s refund, in the panel\'s words');
        $this->send($this->tap(Nav::to('pyv', 'rfg', $payment->id), chat: 7100));
        self::assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
    }

    public function testTheReviewQueueApprovesAndRejectsOneReceiptAfterAnother(): void
    {
        $this->owner();
        $this->receiptPicture();
        $sara = $this->customer(['telegram_id' => 5252, 'first_name' => 'Sara']);
        $older = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $newer = $this->receipt($this->cardPayment($this->topUpOrder($sara, '70000.00'), $this->card), messageId: 778);
        $last = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '90000.00'), $this->card), messageId: 779);

        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7001));
        self::assertContains(Nav::to('pyv', 'nx', 0), $this->callbacks(0), 'the queue started from the review tab');
        $this->send($this->tap(Nav::to('pyv', 'nx', 0), chat: 7001));
        self::assertStringContainsString('پرداخت ' . Fmt::id($last->id), implode("\n", $this->said()), 'the newest receipt first, as the list has them');
        self::assertContains(Nav::to('pyv', 'okn', $last->id), $this->callbacks($this->windowCall()));
        self::assertContains(Nav::to('pyv', 'no', $last->id, 'q'), $this->callbacks($this->windowCall()));

        // Approved, and on to the next receipt in one tap.
        $this->send($this->tap(Nav::to('pyv', 'okn', $last->id), chat: 7001));
        self::assertSame(PaymentStatus::Paid, $last->refresh()->status, 'approved through the panel\'s route');
        self::assertStringContainsString('کیف پول شارژ شد', (string) $this->popup());
        self::assertStringContainsString('پرداخت ' . Fmt::id($newer->id), implode("\n", $this->said()), 'the next receipt drawn');

        // Rejected without a note, and on again.
        $this->send($this->tap(Nav::to('pyv', 'no', $newer->id, 'q'), chat: 7001));
        self::assertContains(Nav::to('pyv', 'nog', $newer->id, 'q'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('pyv', 'nog', $newer->id, 'q'), chat: 7001));
        self::assertSame(PaymentStatus::Failed, $newer->refresh()->status);
        self::assertStringContainsString('پرداخت ' . Fmt::id($older->id), implode("\n", $this->said()));

        // The last one rejected with a reason typed: the queue empty, the list says so.
        $this->send($this->tap(Nav::to('pyv', 'non', $older->id, 'q'), chat: 7001));
        $this->send($this->message('رسید ناخواناست', chat: 7001));
        self::assertSame(PaymentStatus::Failed, $older->refresh()->status);
        self::assertStringContainsString('رسید ناخواناست', (string) $older->note);
        $screen = implode("\n", $this->said());
        self::assertStringContainsString('رسید دیگری در انتظار بررسی نیست', $screen);
        self::assertStringContainsString('فعلا رسیدی منتظر بررسی نیست', $screen, 'the review tab, empty now');
    }

    public function testASkippedReceiptComesRoundAgainAtTheEnd(): void
    {
        $this->owner();
        $this->receiptPicture();
        $oldest = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $middle = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '70000.00'), $this->card), messageId: 778);
        $newest = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '90000.00'), $this->card), messageId: 779);

        // The newest opened from Ali's own payments once: the queue walks it all the same.
        $this->send($this->tap(Nav::to('pyv', 'show', $newest->id, 'from.uslp'), chat: 7001));
        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'nx', 0), chat: 7001));
        self::assertContains(Nav::to('pyv', 'okn', $newest->id), $this->callbacks($this->windowCall()), 'walked to: in the queue');
        self::assertContains(Nav::to('py'), $this->callbacks($this->windowCall()), 'back to the payments, not Ali\'s list');

        $this->send($this->tap(Nav::to('pyv', 'nx', $newest->id), chat: 7001));
        self::assertContains(Nav::to('pyv', 'okn', $middle->id), $this->callbacks($this->windowCall()), 'skipped to the next');
        $this->send($this->tap(Nav::to('pyv', 'okn', $middle->id), chat: 7001));
        self::assertContains(Nav::to('pyv', 'okn', $oldest->id), $this->callbacks($this->windowCall()), 'on to the one after it, not back to the skipped one');
        $this->send($this->tap(Nav::to('pyv', 'okn', $oldest->id), chat: 7001));
        self::assertContains(Nav::to('pyv', 'okn', $newest->id), $this->callbacks($this->windowCall()), 'the skipped one last');
        self::assertSame([PaymentStatus::Paid, PaymentStatus::Paid, PaymentStatus::AwaitingReview], [$middle->refresh()->status, $oldest->refresh()->status, $newest->refresh()->status]);
    }

    public function testSkippingTheLastReceiptComesRoundToTheFirst(): void
    {
        $this->owner();
        $this->receiptPicture();
        $oldest = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $middle = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '70000.00'), $this->card), messageId: 778);
        $newest = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '90000.00'), $this->card), messageId: 779);

        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'nx', $middle->id), chat: 7001));
        self::assertContains(Nav::to('pyv', 'okn', $oldest->id), $this->callbacks($this->windowCall()), 'on to the last');
        $this->send($this->tap(Nav::to('pyv', 'nx', $oldest->id), chat: 7001));
        self::assertContains(Nav::to('pyv', 'okn', $newest->id), $this->callbacks($this->windowCall()), 'past the last, the first skipped comes round');
        self::assertStringNotContainsString('رسید دیگری در انتظار بررسی نیست', (string) $this->popup());

        // The only one waiting skipped: nothing else is, said over the review tab.
        $this->send($this->tap(Nav::to('pyv', 'okn', $newest->id), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'okn', $middle->id), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'nx', $oldest->id), chat: 7001));
        self::assertStringContainsString('رسید دیگری در انتظار بررسی نیست', (string) $this->popup());
        self::assertContains(Nav::to('pyv', 'show', $oldest->id), $this->callbacks($this->windowCall()), 'the review tab, the one skipped still in it');
        self::assertSame([PaymentStatus::Paid, PaymentStatus::Paid, PaymentStatus::AwaitingReview], [$newest->refresh()->status, $middle->refresh()->status, $oldest->refresh()->status]);
    }

    public function testAnOldWindowNeverApprovesAReceiptDecidedMeanwhile(): void
    {
        $this->owner();
        $this->receiptPicture();
        $older = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $newer = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '70000.00'), $this->card), messageId: 778);

        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'nx', 0), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'okn', $newer->id), chat: 7001));
        self::assertSame(PaymentStatus::Paid, $newer->refresh()->status);
        self::assertContains(Nav::to('pyv', 'okn', $older->id), $this->callbacks($this->windowCall()), 'on to the next receipt');

        // The same button again (a second tap, an old window): refused, the next receipt never approved by it.
        $this->send($this->tap(Nav::to('pyv', 'okn', $newer->id), chat: 7001));
        self::assertStringContainsString('دیگر در انتظار بررسی نیست', (string) $this->popup());
        self::assertNotContains('editMessageMedia', $this->calls(), 'the window left on the receipt it shows');
        self::assertSame(PaymentStatus::AwaitingReview, $older->refresh()->status);
        self::assertSame('70000.00', $this->ali->balance(), 'the wallet charged once');

        // Rejected by another admin while the owner's window still shows it in review: never approved over, by hand.
        $this->service(PaymentService::class)->reject($older, 'Boss', 'رسید ناخواناست');
        foreach ([Nav::to('pyv', 'okn', $older->id), Nav::to('pyv', 'ok', $older->id, 'r')] as $tap) {
            $this->send($this->tap($tap, chat: 7001));
            self::assertStringContainsString('دیگر در انتظار بررسی نیست', (string) $this->popup(), $tap);
            self::assertSame(PaymentStatus::Failed, $older->refresh()->status, $tap);
            self::assertSame(OrderStatus::Pending, $older->order->refresh()->status, $tap);
        }
        self::assertSame('70000.00', $this->ali->balance());
    }

    public function testADeliveryThatFailsKeepsTheQueueOnThePayment(): void
    {
        $this->owner();
        $this->receiptPicture();
        $this->withoutQr();
        $server = $this->sellingServer('Berlin');
        $plan = $this->plan(['name' => 'Basic', 'price' => '50000.00'], $server);
        $server->forceFill(['is_active' => false])->save();
        $stuck = $this->receipt($this->cardPayment($this->purchaseOrder($this->ali, $plan, $server), $this->card));
        $other = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '70000.00'), $this->card), messageId: 778);

        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'show', $stuck->id), chat: 7001));
        $this->send($this->tap(Nav::to('pyv', 'okn', $stuck->id), chat: 7001));
        self::assertSame(PaymentStatus::Paid, $stuck->refresh()->status, 'approved');
        self::assertStringContainsString('تحویل انجام نشد', (string) $this->popup(), 'the failed delivery said');
        $screen = implode("\n", $this->said());
        self::assertStringContainsString('پرداخت ' . Fmt::id($stuck->id), $screen, 'still on the payment');
        self::assertStringNotContainsString('پرداخت ' . Fmt::id($other->id), $screen);
        self::assertSame(PaymentStatus::AwaitingReview, $other->refresh()->status, 'the next receipt untouched');
        self::assertContains(Nav::to('pyv', 'rt', $stuck->id), $this->callbacks($this->windowCall()), 'its retry right there');
    }

    public function testAReceiptOpenedFromACustomersListIsApprovedWhereItIs(): void
    {
        $this->owner();
        $this->receiptPicture();
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '70000.00'), $this->card), messageId: 778);

        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id, 'from.uslp'), chat: 7001));
        $buttons = $this->callbacks($this->windowCall());
        self::assertContains(Nav::to('pyv', 'ok', $payment->id, 'r'), $buttons, 'approved and kept on screen — while it is still in review');
        self::assertNotContains(Nav::to('pyv', 'okn', $payment->id), $buttons, 'no walking the shop\'s queue from a customer\'s page');
        self::assertNotContains(Nav::to('pyv', 'nx', $payment->id), $buttons);
    }

    public function testAReceiptIsRejectedWithAReasonTyped(): void
    {
        $this->owner();
        $payment = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));

        $this->send($this->tap(Nav::to('pyv', 'no', $payment->id), chat: 7001));
        self::assertStringContainsString('رد شود؟', $this->said()[0]);
        self::assertContains(Nav::to('pyv', 'non', $payment->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pyv', 'non', $payment->id), chat: 7001));
        self::assertStringContainsString('دلیل رد کردن', $this->said()[0]);
        $this->send($this->message('مبلغ رسید با سفارش نمی‌خواند', chat: 7001));
        self::assertSame(PaymentStatus::Failed, $payment->refresh()->status, 'rejected through the panel\'s route');
        self::assertStringContainsString('مبلغ رسید با سفارش نمی‌خواند', (string) $payment->note);
        self::assertStringContainsString('رد شد', implode("\n", $this->said()));
    }

    public function testAPaymentWithoutAReceiptIsRemindedAndCancelled(): void
    {
        $this->owner();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);

        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id), chat: 7001));
        self::assertStringContainsString('مشتری هنوز رسیدی نفرستاده است', $this->said()[0]);
        self::assertContains(Nav::to('pyv', 'rm', $payment->id), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pyv', 'rm', $payment->id), chat: 7001));
        self::assertStringContainsString('یادآوری فرستاده شد', (string) $this->popup());

        $this->send($this->tap(Nav::to('pyv', 'cx', $payment->id), chat: 7001));
        self::assertStringContainsString('لغو شود؟', $this->said()[0], 'asked first');
        $this->send($this->tap(Nav::to('pyv', 'cxg', $payment->id), chat: 7001));
        self::assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
    }

    public function testAnOnlinePaymentIsAskedOfItsGateway(): void
    {
        $this->owner();
        $this->config(['app.url' => 'https://shop.example']);
        $this->cubePay();
        $method = $this->cubePayMethod();
        $orders = $this->service(OrderService::class);
        $payment = $this->service(Checkout::class)->pay($this->ali, $method, OrderType::WalletTopUp, '50000', fn(?OrderKey $key): Order => $orders->openTopUp($this->ali, 50000, $key))->payment()->refresh();

        $this->send($this->tap(Nav::to('pyv', 'show', $payment->id), chat: 7001));
        self::assertStringContainsString('نتیجه‌اش را درگاه می‌گوید', $this->said()[0], 'no receipt: the gateway says');
        self::assertStringContainsString('شناسه در درگاه', $this->said()[0]);
        self::assertContains(Nav::to('pyv', 'ck', $payment->id), $this->callbacks(0));
        self::assertNotContains(Nav::to('pyv', 'ok', $payment->id), $this->callbacks(0), 'nobody approves it by hand');

        $this->send($this->tap(Nav::to('pyv', 'ck', $payment->id), chat: 7001));
        self::assertStringContainsString('هنوز در درگاه پرداخت نشده است', (string) $this->popup());

        $this->cubePay()->pay((string) $payment->reference);
        $this->send($this->tap(Nav::to('pyv', 'ck', $payment->id), chat: 7001));
        self::assertStringContainsString('در درگاه پرداخت شده بود؛ تایید شد و کیف پول شارژ شد', (string) $this->popup());
        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'settled on the gateway\'s word');
    }

    public function testReceiptsAreDismissedTogetherOnlyWithTheGrant(): void
    {
        $this->staff();
        $first = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card));
        $second = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '70000.00'), $this->card), messageId: 778);
        $third = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '90000.00'), $this->card), messageId: 779);

        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7100));
        self::assertNotContains(Nav::to('pyx'), $this->callbacks(0), 'dismissing in bulk is a grant');
        $this->send($this->tap(Nav::to('pyx', 'all'), chat: 7100));
        self::assertStringContainsString('مدیریت فروشگاه', $this->said()[0], 'a screen it may not open is the home');

        $this->botSettings('bot_admins', ['bot_admins_grant_payments' => true]);
        $this->send($this->tap(Nav::to('py', 'f', 'status', 'awaiting_review'), chat: 7100));
        self::assertContains(Nav::to('pyx'), $this->callbacks(0));
        self::assertContains(Nav::to('pyx', 'all'), $this->callbacks(0));

        $this->send($this->tap(Nav::to('pyx'), chat: 7100));
        $this->send($this->tap(Nav::to('pyx', 't', $first->id), chat: 7100));
        self::assertContains('✅ ۵۰٬۰۰۰ تومان · Ali · ' . Fmt::id($first->id), $this->buttonLabels(0), 'ticked');
        $this->send($this->tap(Nav::to('pyx', 'dis'), chat: 7100));
        self::assertStringContainsString('۱ رسید انتخاب‌شده رد شود؟', $this->said()[0]);
        self::assertSame([Sales::WITHOUT_NOTE, Sales::WITH_NOTE, Words::CANCEL], $this->buttonLabels(0), 'with a note, without (drawn right to left), or not at all');
        $this->send($this->tap(Nav::to('pyx', 'dig'), chat: 7100));
        self::assertStringContainsString('۱ رسید رد شد', (string) $this->popup());
        self::assertSame([PaymentStatus::Failed, PaymentStatus::AwaitingReview], [$first->refresh()->status, $second->refresh()->status]);

        $this->send($this->tap(Nav::to('pyx', 'all'), chat: 7100));
        self::assertStringContainsString('همه ۲ رسیدی', $this->said()[0]);
        $shown = now()->getTimestamp();
        self::assertContains(Nav::to('pyx', 'aln', $shown), $this->callbacks(0), 'the moment the confirmation was shown goes with it');
        // A receipt that came while the admin read the confirmation is left for them to see.
        Carbon::setTestNow(now()->addMinute());
        $late = $this->receipt($this->cardPayment($this->topUpOrder($this->ali, '30000.00'), $this->card), messageId: 780);
        $this->send($this->tap(Nav::to('pyx', 'aln', $shown), chat: 7100));
        $this->send($this->message('رسید تکراری بود', chat: 7100));
        self::assertSame([PaymentStatus::Failed, PaymentStatus::Failed], [$second->refresh()->status, $third->refresh()->status]);
        self::assertSame(PaymentStatus::AwaitingReview, $late->refresh()->status, 'held to what had come by the confirmation');
        self::assertStringContainsString('۲ رسید رد شد', implode("\n", $this->said()));
    }

    public function testNoReceiptWaitingOffersNothingToDismissOrReview(): void
    {
        $this->owner();
        $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);

        $this->send($this->tap(Nav::to('pyx'), chat: 7001));
        self::assertStringContainsString('فعلا رسیدی منتظر بررسی نیست', $this->said()[0]);
        $buttons = $this->callbacks(0);
        foreach ([Nav::to('pyx', 'pa'), Nav::to('pyx', 'all'), Nav::to('pyv', 'nx', 0)] as $none) {
            self::assertNotContains($none, $buttons, $none);
        }
        self::assertContains(Nav::to('py'), $buttons, 'the way back to the payments');
    }

    public function testAnAdminApprovesAReceiptInReviewButNothingByHand(): void
    {
        $this->staff();
        $this->owner();
        $pending = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card);

        $this->send($this->tap(Nav::to('pyv', 'show', $pending->id), chat: 7001));
        self::assertContains(Nav::to('pyv', 'ok', $pending->id), $this->callbacks($this->windowCall()), 'the owner approves one by hand');
        $this->send($this->tap(Nav::to('pyv', 'show', $pending->id), chat: 7100));
        self::assertNotContains(Nav::to('pyv', 'ok', $pending->id), $this->callbacks($this->windowCall()), 'an admin only a receipt in review');

        $this->send($this->tap(Nav::to('pyv', 'ok', $pending->id), chat: 7100));
        self::assertSame(ActorRefusedException::RECEIPT_FIRST_IN_BOT, $this->popup(), 'refused in the bot\'s words');
        self::assertSame(PaymentStatus::Pending, $pending->refresh()->status);
    }

    public function testTheBankSmsAreListedWithWhatCameOfEach(): void
    {
        $this->owner();
        $payment = $this->cardPayment($this->topUpOrder($this->ali, '50000.00'), $this->card, ['status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $matched = Deposit::query()->create(['source' => 'forapp', 'external_id' => 'sms-1', 'status' => DepositStatus::Matched, 'amount_rial' => 500000, 'bank' => 'ملت', 'sender' => '+98700', 'body' => 'واریز ۵۰۰٬۰۰۰ ریال', 'payment_id' => $payment->id, 'received_at' => now()]);
        $lost = Deposit::query()->create(['source' => 'forapp', 'external_id' => 'sms-2', 'status' => DepositStatus::Unmatched, 'amount_rial' => 120000, 'note' => 'پرداختی با این مبلغ باز نیست.', 'received_at' => now()]);

        $this->send($this->tap(Nav::to('dp'), chat: 7001));
        self::assertStringContainsString('تایید خودکار واریز خاموش است', $this->said()[0], 'said while it is off');
        self::assertContains(Nav::to('dpv', 'show', $matched->id), $this->callbacks(0));
        $this->send($this->tap(Nav::to('dp', 'f', 'status', 'unmatched'), chat: 7001));
        self::assertSame([Nav::to('dpv', 'show', $lost->id)], array_values(array_filter($this->callbacks(0), static fn(string $data): bool => str_starts_with($data, 'a:dpv:'))));

        $this->send($this->tap(Nav::to('dp', 'f', 'status', '_'), chat: 7001));
        $this->send($this->tap(Nav::to('dpv', 'show', $matched->id), chat: 7001));
        self::assertStringContainsString('واریز ۵۰۰٬۰۰۰ ریال', $this->said()[0], 'the SMS as it came');
        self::assertStringContainsString('متن پیامک', $this->said()[0]);
        self::assertContains(Nav::to('pyv', 'show', $payment->id), $this->callbacks(0), 'the payment it approved');

        // Rikhtam sends no SMS, only what it read of one: said so, as the panel does.
        $told = Deposit::query()->create(['source' => 'rikhtam', 'external_id' => 'rk-1', 'status' => DepositStatus::Unmatched, 'amount_rial' => 130000, 'body' => 'واریز به حساب ····1234', 'received_at' => now()]);
        $this->send($this->tap(Nav::to('dp'), chat: 7001));
        self::assertStringContainsString('فوراپ از گوشی فروشگاه یا ریختم', $this->said()[0]);
        $this->send($this->tap(Nav::to('dpv', 'show', $told->id), chat: 7001));
        self::assertStringContainsString('ریختم خود پیامک را نمی‌فرستد', $this->said()[0]);
        self::assertStringContainsString('آنچه ریختم فرستاد', $this->said()[0]);
        self::assertStringNotContainsString('متن پیامک', $this->said()[0]);
    }

    public function testAnAdminSeesTheListsButNoDepositSettings(): void
    {
        $this->staff();
        Deposit::query()->create(['source' => 'forapp', 'external_id' => 'sms-1', 'status' => DepositStatus::Unmatched, 'amount_rial' => 120000, 'received_at' => now()]);

        $this->send($this->tap(Nav::to('hl'), chat: 7100));
        self::assertContains(Nav::to('dp'), $this->callbacks(0));
        $this->send($this->tap(Nav::to('dp'), chat: 7100));
        self::assertStringNotContainsString('تایید خودکار واریز خاموش است', $this->said()[0], 'the settings are the owner\'s and an agent\'s');
    }

    /** Telegram hands the receipt's picture over: a JPEG. */
    private function receiptPicture(): void
    {
        $this->telegram()->on('getFile', static fn(): array => ['file_id' => 'receipt-1', 'file_path' => 'photos/file_1.jpg']);
        $this->telegram()->on('file_1.jpg', static fn(): Response => new Response(200, ['Content-Type' => 'image/jpeg'], "\xFF\xD8\xFF\xE0" . str_repeat("\0", 32)));
    }

    /** One of the shop's admins, 7100, managing from the bot. */
    private function staff(): void
    {
        $this->customer(['telegram_id' => 7100, 'first_name' => 'Boss', 'role' => UserRole::Admin]);
    }
}
