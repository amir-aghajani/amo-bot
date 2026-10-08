<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Scheduler;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Tasks\AutoApproveReceiptsTask;
use App\Modules\Providers\Models\Server;
use App\Modules\Telegram\Notifications\ServiceCard;
use App\Modules\Telegram\Texts\BotText;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * A card-to-card method with a review window: a receipt nobody reviews inside it is accepted by the timer — every
 * minute, in every shop —, with nobody's name on it; the order is delivered and the customer gets their service in the
 * bot. A receipt whose order was paid another way, or closed as the timer reached it, is left to support; one approval
 * that breaks does not hold up the others.
 */
final class AutoApproveReceiptsTest extends DatabaseTestCase
{
    private User $customer;
    private Plan $plan;
    private Server $server;
    private PaymentMethod $timed;
    private PaymentMethod $manualOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutQr();
        $this->telegram();

        $this->server = $this->sellingServer();
        $this->plan = $this->plan(on: $this->server);
        $this->customer = $this->customer();

        $this->timed = $this->cardMethod(autoApproveAfter: 30);
        $this->manualOnly = $this->cardMethod('کارت به کارت (ملی)', 0, ['config' => ['card_number' => '5892101012345670']]);
    }

    public function testTheTimerLooksEveryMinuteInEveryShop(): void
    {
        self::assertSame(
            ['task' => AutoApproveReceiptsTask::class, 'interval' => 60, 'eachBot' => true],
            $this->service(Scheduler::class)->tasks()[AutoApproveReceiptsTask::class],
        );
    }

    public function testAReceiptIsAcceptedOnceItsWindowPassesAndTheCustomerGetsTheSubscription(): void
    {
        $payment = $this->receiptOn($this->timed);
        $sent = now();

        $this->task()->run();
        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'not yet: the window is 30 minutes');
        self::assertSame([], $this->telegram()->calls());

        Carbon::setTestNow($sent->copy()->addMinutes(29));
        $this->task()->run();
        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'still inside the window');

        Carbon::setTestNow($sent->copy()->addMinutes(31));
        $this->task()->run();

        $payment->refresh();
        self::assertSame(PaymentStatus::Paid, $payment->status);
        self::assertNull($payment->reviewer, 'nobody reviewed it by hand');
        self::assertTrue($payment->wasAutoApproved());
        self::assertSame(OrderStatus::Fulfilled, $payment->order->status);
        self::assertCount(1, FakeProvider::$created, 'the client was created on the panel');
        $service = $payment->order->subscription ?? self::fail('nothing delivered');
        self::assertSame([self::text(BotText::PaySuccess, $this->service(ServiceCard::class)->values($service))], $this->telegram()->sentTo(self::TELEGRAM_ID), 'the service, as support approving it would deliver it');
        self::assertSame(self::RECEIPT_MESSAGE, $this->telegram()->replyTarget(0), 'as a reply to the receipt');

        $this->task()->run();
        self::assertCount(1, $this->telegram()->calls(), 'settled payments are not touched again');
    }

    public function testMethodsWithoutAWindowWaitForAnAdmin(): void
    {
        $payment = $this->receiptOn($this->manualOnly);

        Carbon::setTestNow(now()->addDays(3));
        $this->task()->run();

        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status);
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAReceiptForAnOrderPaidAnotherWayIsLeftToTheAdmin(): void
    {
        $payment = $this->receiptOn($this->timed);
        // The customer paid the same order again, and that one settled first.
        $this->service(OrderService::class)->markPaid($payment->order);

        Carbon::setTestNow(now()->addHours(2));
        $this->task()->run();

        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'nothing to accept it against');
        self::assertSame([], $this->telegram()->calls());
    }

    public function testAReceiptWhoseOrderClosedAsTheTimerReachedItIsLeftToSupport(): void
    {
        $payment = $this->receiptOn($this->timed);
        Carbon::setTestNow(now()->addHours(2));
        $logs = $this->logs();

        $this->whileTheOrderClosesOnceRead(fn() => $this->task()->run());

        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'the approval unwound: the receipt is support\'s');
        self::assertSame([], FakeProvider::$created);
        self::assertSame([], $this->telegram()->calls());
        self::assertFalse($logs->hasErrorRecords(), 'a decision that lost is no failure');
    }

    public function testTheCustomerIsToldWhenFulfilmentFailsAfterAutoApproval(): void
    {
        $payment = $this->receiptOn($this->timed);
        $this->server->forceFill(['is_active' => false])->save(); // no server can take the client any more

        Carbon::setTestNow(now()->addHours(1));
        $this->task()->run();

        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status, 'the money is accepted');
        self::assertSame(OrderStatus::Failed, $payment->order->status);
        self::assertSame([self::text(BotText::PayProvisionFailed)], $this->telegram()->sentTo(self::TELEGRAM_ID));
    }

    public function testAnApprovalThatBreaksIsLoggedAndTheOthersStillGo(): void
    {
        // A traffic order of a customer on an agency level whose shop was never opened: its delivery has nothing to add
        // the traffic to — a fault of the shop's own.
        $broken = $this->service(OrderService::class)->openTraffic($this->customer(['telegram_id' => 7007, 'agency_level_id' => $this->agencyLevel()->id]), 20);
        $first = $this->receipt($this->cardPayment($broken, $this->timed));
        Carbon::setTestNow(now()->addMinute());
        $second = $this->receiptOn($this->timed);
        $logs = $this->logs();

        Carbon::setTestNow(now()->addHour());
        $this->task()->run();

        self::assertTrue($logs->hasErrorThatContains("Auto-approval of payment {$first->id} failed"));
        self::assertSame([OrderStatus::Failed, OrderService::DELIVERY_BROKEN], [$broken->refresh()->status, $broken->notes]);
        self::assertSame(OrderStatus::Fulfilled, $second->refresh()->order->status, 'the next receipt was still accepted');
    }

    /** A purchase paid with the method, its receipt just sent. */
    private function receiptOn(PaymentMethod $method): Payment
    {
        $order = $this->service(OrderService::class)->openPurchase($this->customer, $this->plan, $this->server);
        $payment = $this->receipt($this->cardPayment($order, $method));

        self::assertSame(PaymentStatus::AwaitingReview, $payment->status);
        self::assertNotNull($payment->receipt_at);

        return $payment;
    }

    private function task(): AutoApproveReceiptsTask
    {
        return $this->service(AutoApproveReceiptsTask::class);
    }
}
