<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Scheduling\Scheduler;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Tasks\ExpireOrdersTask;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Users\Models\User;
use Illuminate\Support\Carbon;
use Tests\DatabaseTestCase;

/**
 * Orders nobody finished paying: once nothing happened to an order nor to any of its payments for EXPIRE_HOURS, the
 * hourly task — in every shop — drops it with its unpaid payments, quietly. A receipt waiting for support keeps its
 * order whatever its age, and so does a payment that moved within the window (a receipt refused) — or a receipt that
 * landed after the task read the order as idle.
 */
final class ExpireOrdersTest extends DatabaseTestCase
{
    private const OPENED = '2026-09-18 10:00:00';

    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::OPENED);
        $this->telegram();
        $this->ali = $this->customer();
        $this->card = $this->cardMethod();
    }

    public function testTheTaskLooksHourlyInEveryShop(): void
    {
        self::assertSame(
            ['task' => ExpireOrdersTask::class, 'interval' => 60 * 60, 'eachBot' => true],
            $this->service(Scheduler::class)->tasks()[ExpireOrdersTask::class],
        );
    }

    public function testAnOrderLeftAloneIsDroppedWithItsUnpaidPaymentsQuietly(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $unfinished = $this->cardPayment($order, $this->card);
        $refused = $this->receipt($this->cardPayment($order, $this->cardMethod('کارت دوم')));
        $this->service(PaymentActions::class)->reject($refused, $this->panelActor(), 'ناخوانا');
        $this->telegram()->reset();

        $this->runAfter(ExpireOrdersTask::EXPIRE_HOURS * 60 - 1);
        self::assertSame(OrderStatus::Pending, $order->refresh()->status, 'a minute short of the window');

        $this->runAfter(ExpireOrdersTask::EXPIRE_HOURS * 60);
        self::assertSame([OrderStatus::Cancelled, OrderService::NOTE_EXPIRED], [$order->refresh()->status, $order->notes]);
        self::assertSame([PaymentStatus::Cancelled, OrderService::NOTE_EXPIRED], [$unfinished->refresh()->status, $unfinished->note]);
        self::assertSame([PaymentStatus::Cancelled, 'ناخوانا'], [$refused->refresh()->status, $refused->note], 'a refused receipt keeps the reason its customer was told');
        self::assertSame([], $this->telegram()->calls(), 'the customer walked away: nobody is told');
    }

    public function testAReceiptWaitingForSupportKeepsItsOrderWhateverItsAge(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $this->receipt($this->cardPayment($order, $this->card));

        $this->runAfter(30 * 24 * 60);

        self::assertSame(OrderStatus::Pending, $order->refresh()->status);
    }

    public function testAPaymentThatMovedWithinTheWindowKeepsItsOrder(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $payment = $this->receipt($this->cardPayment($order, $this->card));
        // Support refused the receipt the day after: the customer may pay again, and has the window from then.
        Carbon::setTestNow(Carbon::parse(self::OPENED)->addDay());
        $this->service(PaymentActions::class)->reject($payment, $this->panelActor(), null);

        $this->runAfter((ExpireOrdersTask::EXPIRE_HOURS + 2) * 60);
        self::assertSame(OrderStatus::Pending, $order->refresh()->status, 'idle itself, but its payment moved within the window');

        $this->runAfter((24 + ExpireOrdersTask::EXPIRE_HOURS) * 60);
        self::assertSame(OrderStatus::Cancelled, $order->refresh()->status, 'two days after the refusal, it goes');
    }

    public function testAReceiptThatLandedAfterTheTaskReadTheOrderKeepsIt(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00');
        $payment = $this->cardPayment($order, $this->card);
        Carbon::setTestNow(Carbon::parse(self::OPENED)->addHours(ExpireOrdersTask::EXPIRE_HOURS + 1));
        // The task read the order idle, with its payments; the customer's receipt landed before it got to it.
        $read = Order::payable()->with('payments')->findOrFail($order->id);
        $this->receipt($payment);

        self::assertFalse($this->service(PaymentService::class)->expire($read, now()->subHours(ExpireOrdersTask::EXPIRE_HOURS)));

        self::assertSame(OrderStatus::Pending, $order->refresh()->status);
        self::assertSame(PaymentStatus::AwaitingReview, $payment->refresh()->status, 'the receipt waits for support');
    }

    /** The task's run `$minutes` after the order was opened. */
    private function runAfter(int $minutes): void
    {
        Carbon::setTestNow(Carbon::parse(self::OPENED)->addMinutes($minutes));
        $this->service(ExpireOrdersTask::class)->run();
    }
}
