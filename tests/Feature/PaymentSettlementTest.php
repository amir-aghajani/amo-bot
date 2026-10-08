<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentActions;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Models\Server;
use App\Modules\Users\Enums\WalletTransactionType;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Models\User;
use App\Modules\Users\Models\WalletTransaction;
use App\Modules\Users\Services\WalletService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;
use Tests\Fakes\FakeProvider;

/**
 * Settling is a compare-and-swap: whatever races — the admin and the auto-approve timer, two tabs deciding the same
 * receipt, two refund clicks, a wallet tap on an order another payment just paid, a refund meeting a delivery — the
 * money moves once, the panel is asked once, and only the call that made the move tells the customer.
 */
final class PaymentSettlementTest extends DatabaseTestCase
{
    private User $ali;
    private PaymentMethod $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram();
        $this->ali = $this->customer(['telegram_id' => 1001]);
        $this->card = $this->cardMethod(autoApproveAfter: 30);
    }

    public function testApprovingTwiceProvisionsOnce(): void
    {
        [$plan, $server] = $this->shop();
        $order = $this->orders()->openPurchase($this->ali, $plan, $server);
        $payment = $this->receipt($this->cardPayment($order, $this->card));

        // The timer and the admin hold the same row; the timer's approval lands first.
        $admin = Payment::query()->findOrFail($payment->id);
        self::assertTrue($this->payments()->approve($payment, null));
        self::assertFalse($this->payments()->approve($admin, 'root'), 'the admin learns it was settled already');

        self::assertCount(1, FakeProvider::$created, 'one client on the panel');
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        $payment->refresh();
        self::assertTrue($payment->wasAutoApproved());
        self::assertNull($payment->reviewer, "the loser's name is not written over the winner's verdict");
    }

    public function testApprovingATopUpTwiceCreditsOnce(): void
    {
        $order = $this->orders()->openTopUp($this->ali, 50000);
        $payment = $this->receipt($this->cardPayment($order, $this->card));
        $twin = Payment::query()->findOrFail($payment->id);

        self::assertTrue($this->payments()->approve($payment, 'root'));
        self::assertFalse($this->payments()->approve($twin, 'root'));

        self::assertSame('50000.00', $this->ali->balance());
        self::assertSame(1, WalletTransaction::query()->where('user_id', $this->ali->id)->count());
    }

    /**
     * Whether it decides a paid payment, and the decision — made by `$who`, with their name as its note.
     *
     * @return array<string, array{bool, \Closure(PaymentActions, Payment, Actor): mixed}>
     */
    public static function decisions(): array
    {
        return [
            'a rejection' => [false, static fn(PaymentActions $actions, Payment $payment, Actor $who) => $actions->reject($payment, $who, $who->reviewer)],
            'a cancel' => [false, static fn(PaymentActions $actions, Payment $payment, Actor $who) => $actions->cancel($payment, $who, $who->reviewer)],
            'a refund' => [true, static fn(PaymentActions $actions, Payment $payment, Actor $who) => $actions->refund($payment, $who, $who->reviewer)],
        ];
    }

    /** @param \Closure(PaymentActions, Payment, Actor): mixed $decide */
    #[DataProvider('decisions')]
    public function testOfTheSameDecisionTakenTwiceAtOnceTheLaterIsRefusedAndTellsNobody(bool $paid, \Closure $decide): void
    {
        $order = $this->orders()->openTopUp($this->ali, 50000);
        $payment = $paid ? $this->paidByCard($order, $this->card) : $this->receipt($this->cardPayment($order, $this->card));
        // Two tabs — or the screen and the report group — read the payment before either decided.
        [$first, $second] = [Payment::query()->with('order')->findOrFail($payment->id), Payment::query()->with('order')->findOrFail($payment->id)];
        $actions = $this->service(PaymentActions::class);

        $decide($actions, $first, $this->panelActor());
        try {
            $decide($actions, $second, $this->panelActor('admin'));
            self::fail('the decision that lost went through');
        } catch (ValidationException $e) {
            self::assertSame(PaymentActions::DECIDED_MEANWHILE, $e->getMessage());
        }

        self::assertSame(['root', 'root'], [$payment->refresh()->reviewer, $payment->note], 'the first verdict stands');
        self::assertSame('0.00', $this->ali->balance(), 'the money moved once');
        self::assertCount(1, $this->telegram()->sentTo(1001), 'the customer hears the decision that stood, once');
    }

    public function testARefundThatMeetsADeliveryClaimedInTheSameMomentWaitsForIt(): void
    {
        $server = $this->sellingServer('Berlin', ['is_active' => false]);
        $payment = $this->paidByCard($this->orders()->openPurchase($this->ali, $this->plan(['price' => '50000.00'], $server), $server), $this->card);
        // Support read the failed delivery as refundable; a retry claimed the order before the refund took it.
        $read = Payment::query()->with('order')->findOrFail($payment->id);
        Order::query()->whereKey($payment->order_id)->update(['status' => OrderStatus::Processing->value]);

        try {
            $this->service(PaymentActions::class)->refund($read, $this->panelActor(), null);
            self::fail('refunded under a delivery');
        } catch (ValidationException $e) {
            self::assertSame(PaymentService::DELIVERY_RUNNING, $e->getMessage());
        }

        self::assertSame([PaymentStatus::Paid, OrderStatus::Processing], [$read->status, $read->order->status], 'nothing moved, and the caller sees it');
        self::assertSame('0.00', $this->ali->balance());
        self::assertSame([], $this->telegram()->calls());
    }

    public function testApprovingAClosedPaymentIsANoOp(): void
    {
        $order = $this->orders()->openTopUp($this->ali, 20000);
        $payment = $this->cardPayment($order, $this->card, ['status' => PaymentStatus::Cancelled]);

        self::assertFalse($this->payments()->approve($payment, 'root'));

        self::assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
        self::assertSame(OrderStatus::Pending, $order->refresh()->status);
        self::assertSame('0.00', $this->ali->balance());
    }

    public function testAGatewayDebitIsRolledBackWhenThePaymentWasSettledMeanwhile(): void
    {
        [$plan, $server] = $this->shop();
        // The wallet could not pay the order: its payment failed with the wallet's refusal, nothing debited.
        $payment = $this->payments()->createForOrder($this->orders()->openPurchase($this->ali, $plan, $server), $this->walletMethod())['payment'];
        self::assertSame([PaymentStatus::Failed, (new InsufficientBalanceException('0'))->getMessage()], [$payment->status, $payment->note]);
        $this->service(WalletService::class)->credit($this->ali, 50000, 'هدیه');
        // This copy still believes the payment is open; the row has been settled by someone else since.
        Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Paid->value]);

        self::assertFalse($this->payments()->approve($payment, 'root'));

        self::assertSame('50000.00', $this->ali->balance(), 'the debit went back with the transaction');
        self::assertSame(1, WalletTransaction::query()->where('user_id', $this->ali->id)->count(), 'only the gift line');
        self::assertSame(PaymentStatus::Paid, $payment->status, 'and the caller sees what won');
    }

    public function testAWalletPaymentOfAnOrderPaidMeanwhileLeavesNothingBehind(): void
    {
        [$plan, $server] = $this->shop();
        $this->service(WalletService::class)->credit($this->ali, 100000, 'هدیه');
        $order = $this->orders()->openPurchase($this->ali, $plan, $server);
        // Two taps in webhook mode: both read the order open before either paid it.
        $stale = Order::query()->findOrFail($order->id);

        $first = $this->payments()->createForOrder($order, $this->walletMethod())['payment'];
        try {
            $this->payments()->createForOrder($stale, $this->walletMethod());
            self::fail('the second payment finds the order paid');
        } catch (OrderNotPayableException $e) {
            self::assertStringContainsString("سفارش #{$order->id}", $e->getMessage());
        }

        self::assertSame([$first->id], Payment::query()->pluck('id')->all(), 'the loser left no payment behind');
        self::assertSame(PaymentStatus::Paid, $first->status);
        self::assertSame('50000.00', $this->ali->balance(), "debited once: the loser's debit was rolled back");
        self::assertSame(1, WalletTransaction::query()->where('user_id', $this->ali->id)->where('type', WalletTransactionType::Debit->value)->count());
        self::assertSame(OrderStatus::Fulfilled, $order->refresh()->status);
        self::assertCount(1, FakeProvider::$created, 'one client on the panel');
    }

    public function testAnOrderClosedMeanwhileUnwindsTheApprovalToo(): void
    {
        $order = $this->orders()->openTopUp($this->ali, 50000);
        $payment = $this->receipt($this->cardPayment($order, $this->card));
        $this->orders()->cancel($order, OrderService::NOTE_CANCELLED_BY_SUPPORT);

        $this->expectException(OrderNotPayableException::class);
        try {
            $this->payments()->approve($payment, 'root');
        } finally {
            self::assertSame(PaymentStatus::AwaitingReview, $payment->status, 'the receipt still waits, for the admin to cancel');
            self::assertNull($payment->reviewer);
            self::assertSame('0.00', $this->ali->balance());
        }
    }

    public function testAnOrderIsDeliveredOnlyByTheProcessThatClaimedIt(): void
    {
        $order = $this->topUpOrder($this->ali, '50000.00', ['status' => OrderStatus::Paid]);
        $other = Order::query()->findOrFail($order->id);
        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::Processing->value]);

        self::assertFalse($this->orders()->deliver($other), 'a live claim is not taken over');
        self::assertSame(OrderStatus::Processing, $other->status);
        self::assertSame('0.00', $this->ali->balance());

        self::assertFalse($this->orders()->markPaid($other), 'nor is a paid order paid again');
        self::assertNull($other->fulfilled_at);
    }

    public function testPaidOneWayTheOrdersOtherWaysWithoutAReceiptGo(): void
    {
        $order = $this->orders()->openTopUp($this->ali, 50000);
        $other = $this->cardMethod('کارت دوم');
        $left = $this->cardPayment($order, $other);
        $inReview = $this->receipt($this->cardPayment($order, $this->cardMethod('کارت سوم')));
        $paying = $this->receipt($this->cardPayment($order, $this->card));

        self::assertTrue($this->payments()->approve($paying, 'root'));

        self::assertSame([PaymentStatus::Cancelled, PaymentService::NOTE_PAID_OTHERWISE], [$left->refresh()->status, $left->note]);
        self::assertSame(PaymentStatus::AwaitingReview, $inReview->refresh()->status, 'a receipt in review stays for support: money may have moved');
    }

    /**
     * A 50,000 Toman plan on a server the shop sells on.
     *
     * @return array{Plan, Server}
     */
    private function shop(): array
    {
        $server = $this->sellingServer('Berlin');

        return [$this->plan(['price' => '50000.00'], $server), $server];
    }

    private function payments(): PaymentService
    {
        return $this->service(PaymentService::class);
    }

    private function orders(): OrderService
    {
        return $this->service(OrderService::class);
    }
}
