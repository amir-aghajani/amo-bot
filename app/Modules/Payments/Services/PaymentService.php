<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Database\Ledger;
use App\Core\Database\Transitions;
use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Contracts\GatewayInterface;
use App\Modules\Payments\DTO\PaymentInitiation;
use App\Modules\Payments\DTO\PaymentResult;
use App\Modules\Payments\Enums\CancelOutcome;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentSettledException;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Rewards\Services\Cashback;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Services\CustomerPictures;
use App\Modules\Users\Services\WalletService;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Payments and their settlement. Every move of a payment is a compare-and-swap on `payments.status` (Transitions) that
 * says whether this call made it, so of two decisions at once (support and the auto-approve timer, two refund clicks)
 * exactly one takes effect — and only that one tells anyone (PaymentActions). Settling a payment and claiming its order
 * (pending → paid) are one transaction; the delivery runs after the commit, since it talks to a panel. Receipts and
 * their verdicts are reported to the report group in the same transaction as the move.
 */
final class PaymentService
{
    /** A refund asked while the delivery it would undo is under way. */
    public const DELIVERY_RUNNING = 'تحویل این سفارش همین حالا در جریان است؛ کمی بعد دوباره امتحان کنید.';

    /** The note on the order's other open payments once it was paid another way (a refused receipt keeps its own reason). */
    public const NOTE_PAID_OTHERWISE = 'سفارش با روش دیگری پرداخت شد';

    /** The longest words a receipt keeps (`receipt_note`): a caption in the bot is cut to it, a website's note held to it. */
    public const RECEIPT_NOTE_MAX = 1024;

    /** The longest refund note: it is written into the refund's wallet line, so a ledger line's limit. */
    public const REFUND_NOTE_MAX = Ledger::NOTE_MAX;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OrderService $orders,
        private readonly WalletService $wallet,
        private readonly TrafficPool $pool,
        private readonly ReferralService $referrals,
        private readonly ShopReports $reports,
        private readonly Receipts $receipts,
        private readonly Cashback $cashback,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Starts paying an open order. The wallet settles at once: the payment is made, charged and the order paid in one
     * transaction, then delivered — losing the order to another payment at that moment leaves nothing behind. A card
     * transfer waits for its receipt: the order's open payment with that card is reused, else a new one is made.
     *
     * @return array{payment: Payment, initiation: PaymentInitiation}
     * @throws OrderNotPayableException the order is not open any more; nothing was written
     */
    public function createForOrder(Order $order, PaymentMethod $method): array
    {
        // A free order is settled by the wallet row even when it is switched off: it takes nothing.
        $free = $method->isWallet() && !Money::isPositive($order->amount);
        if ((!$method->enabled && !$free) || !PaymentMethods::pays($method, $order->type)) {
            throw new \InvalidArgumentException("Payment method #{$method->id} cannot pay order #{$order->id}.");
        }
        $gateway = $this->gateways->forMethod($method);
        $initiation = $gateway->initiate();

        if (!$initiation->isInstant()) {
            return ['payment' => $this->openPayment($order, $method), 'initiation' => $initiation];
        }

        try {
            [$payment, $result] = $this->db->transaction(function () use ($order, $method, $gateway): array {
                $payment = $this->newPayment($order, $method);

                return [$payment, $this->settle($payment, $gateway, null, PaymentStatus::open())];
            });
        } catch (OrderNotPayableException $e) {
            $this->logger->info('Order {order} closed before its {gateway} payment settled: rolled back', ['order' => $order->id, 'gateway' => $method->driver]);

            throw $e;
        }
        if ($result->success) {
            $this->settled($payment);
        }

        return ['payment' => $payment->refresh(), 'initiation' => $initiation];
    }

    /**
     * Accepts a card payment — its receipt, or money support saw some other way — by `$reviewer`, or by the review window
     * (null reviewer). With `$receiptOnly` it moves only from awaiting review, so a receipt rejected or cancelled at the
     * same moment is not approved after all. True when this call settled it; false when it was decided otherwise first.
     *
     * @throws OrderNotPayableException the order was paid another way or cancelled in the same moment; nothing was written
     */
    public function approve(Payment $payment, ?string $reviewer, bool $receiptOnly = false): bool
    {
        $from = $receiptOnly ? [PaymentStatus::AwaitingReview] : PaymentStatus::open();
        if (!in_array($payment->status, $from, true)) {
            return false;
        }

        try {
            $result = $this->db->transaction(fn(): PaymentResult => $this->settle($payment, $this->gateways->forPayment($payment), $reviewer, $from));
        } catch (PaymentSettledException) {
            $this->logger->info('Payment {id} was decided by another process while being approved', ['id' => $payment->id]);

            return false;
        } catch (OrderNotPayableException $e) {
            // The rollback took the payment's move back; the model must not go on believing it is paid.
            $payment->refresh();

            throw $e;
        }
        if ($result->success) {
            $this->settled($payment);
        }

        return $result->success;
    }

    /**
     * Whether the payment waits for its receipt: a pending card transfer whose order is open. Both the bot and the
     * website take a receipt by this rule; of two receipts at once, submit()'s compare-and-swap decides.
     */
    public function awaitsReceipt(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $this->gateways->isManual($payment->method)
            && $payment->order->status === OrderStatus::Pending;
    }

    /**
     * Takes a receipt sent in the bot: its Telegram file id, file name, caption, and the message every verdict replies to.
     * True when this call took it (submit()).
     */
    public function submitReceipt(Payment $payment, string $fileId, ?string $note, ?string $name, ?int $messageId): bool
    {
        return $this->submit($payment, ['receipt_file_id' => $fileId, 'receipt_message_id' => $messageId], $note, $name);
    }

    /**
     * Takes a receipt uploaded from the website (`$path`: Receipts::keep()'s name). True when this call took it; otherwise
     * the caller discards the picture.
     */
    public function submitUpload(Payment $payment, string $path, ?string $note, ?string $name): bool
    {
        return $this->submit($payment, ['receipt_path' => $path], $note, $name);
    }

    /**
     * Refuses a receipt (awaiting review → failed) with the reviewer's reason as its note. The order stays open for the
     * customer to pay again. True when this call decided it.
     */
    public function reject(Payment $payment, string $reviewer, ?string $note): bool
    {
        return $this->db->transaction(function () use ($payment, $reviewer, $note): bool {
            $rejected = Transitions::move($payment, 'status', [PaymentStatus::AwaitingReview], PaymentStatus::Failed, ['reviewer' => $reviewer, 'note' => $note]);
            if ($rejected) {
                $this->reports->receiptRejected($payment, $note);
            }

            return $rejected;
        });
    }

    /**
     * The payments screen's cancel: drops an unpaid payment and, with it, its open order and the order's other open
     * payments — unless another payment of the order has a receipt in review, which may be the money: then this payment
     * goes alone and the rest is left for support.
     */
    public function cancel(Payment $payment, string $reviewer, ?string $note): CancelOutcome
    {
        return $this->dropping($payment, $reviewer, $note, sparingReceipts: true);
    }

    /**
     * The orders screen's cancel (OrderActions::cancel()): drops the payment, its order and every other open payment of
     * it, receipts in review included.
     */
    public function cancelWithOrder(Payment $payment, string $reviewer, ?string $note): CancelOutcome
    {
        return $this->dropping($payment, $reviewer, $note, sparingReceipts: false);
    }

    /**
     * Cancels, quietly, an order the customer walked away from — it and its payments untouched since `$idleSince` — with
     * its unpaid payments (ExpireOrdersTask); never while a receipt of it waits for support. Under locks on the order and
     * its payments, so a receipt arriving at the same moment either comes first or finds it cancelled. Uploaded receipts
     * of those payments are deleted after the commit. `$note` says why (an expired code hold, or unpaid in time). True
     * when this call cancelled it.
     */
    public function expire(Order $order, \DateTimeInterface $idleSince, string $note = OrderService::NOTE_EXPIRED): bool
    {
        $uploads = [];
        $expired = $this->db->transaction(function () use ($order, $idleSince, $note, &$uploads): bool {
            $row = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== OrderStatus::Pending || $row->updated_at > $idleSince) {
                return false;
            }
            $payments = $row->payments()->lockForUpdate()->get();
            $kept = $payments->contains(static fn(Payment $payment): bool => $payment->status === PaymentStatus::AwaitingReview
                || ($payment->status->isOpen() && $payment->updated_at > $idleSince));
            if ($kept) {
                return false;
            }

            foreach ($payments as $payment) {
                // A refused receipt keeps its reason — the word its customer was told —; any other gets why it went.
                if (Transitions::move($payment, 'status', [PaymentStatus::Pending, PaymentStatus::Failed], PaymentStatus::Cancelled, ['note' => $payment->note ?? $note]) && $payment->receipt_path !== null) {
                    $uploads[] = $payment->receipt_path;
                }
            }

            return Transitions::move($order, 'status', [OrderStatus::Pending], OrderStatus::Cancelled, ['notes' => $note]);
        });
        if ($expired) {
            $this->receipts->discard(...$uploads);
        }

        return $expired;
    }

    /**
     * Gives a paid payment back, in one transaction, by what its order bought:
     * - a service, a renewal or an extra: the amount (less the cashback it earned) goes to the customer's wallet. What it
     *   gave was taken back already (PaymentActions::refund(), RefundedOrders) — a purchase's service removed, a
     *   renewal's or an extra's term and traffic —, and in an agent's shop the traffic it drew goes back to the pool;
     * - an agent's traffic: the amount goes to their wallet and the traffic comes back out of their pool (refused once sold);
     * - a top-up: support returns the money outside the shop, so the top-up comes back out of the wallet (refused once spent).
     * Refused while the order's delivery is under way, and — given `$seen`, the state what it gave was taken back by — when
     * the order is no longer in that state (delivered since). A referrer's commission stands. True when this call refunded
     * it.
     *
     * @throws RefundRefusedException what the order put in the shop is no longer there
     * @throws ValidationException 422 on `status`: the order's delivery is under way, or ended since it was seen
     */
    public function refund(Payment $payment, string $reviewer, ?string $note, ?OrderStatus $seen = null): bool
    {
        try {
            return $this->refundWithin($payment, $reviewer, $note, $seen);
        } catch (RefundRefusedException|ValidationException $e) {
            // The rollback took the moves back; the models must not go on believing them.
            $payment->refresh();
            $payment->order->refresh();

            throw $e;
        }
    }

    private function refundWithin(Payment $payment, string $reviewer, ?string $note, ?OrderStatus $seen): bool
    {
        return $this->db->transaction(function () use ($payment, $reviewer, $note, $seen): bool {
            $order = $payment->order;
            // The order first, under a lock: a delivery claiming it now waits for this transaction, then finds it refunded.
            $state = Order::query()->whereKey($order->id)->lockForUpdate()->value('status');
            if (!Transitions::move($payment, 'status', [PaymentStatus::Paid], PaymentStatus::Refunded, ['reviewer' => $reviewer, 'note' => $note])) {
                return false;
            }
            $delivered = $state === OrderStatus::Fulfilled;
            $refundable = in_array($state, [OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Fulfilled], true) && ($seen === null || $state === $seen);
            if (!$refundable || !Transitions::move($order, 'status', [$state], OrderStatus::Refunded)) {
                throw ValidationException::on('status', self::DELIVERY_RUNNING);
            }

            $customer = $order->user;
            if ($order->type === OrderType::WalletTopUp) {
                if ($delivered) {
                    try {
                        $this->wallet->debit($customer, $payment->amount, WalletService::describeTopUpRefund($payment, $note), $customer->credit());
                    } catch (InsufficientBalanceException $e) {
                        throw RefundRefusedException::topUpSpent($e->balance);
                    }
                }

                return true;
            }

            if ($order->type === OrderType::Traffic && $delivered) {
                $bot = $customer->ownBot ?? throw new \LogicException("The agent of traffic order #{$order->id} has no bot.");
                if (!$this->pool->takeBack($order, $bot)) {
                    throw RefundRefusedException::trafficSold($bot->trafficBalance());
                }
            }
            // An agent's sale undone: the traffic it drew (a purchase, a renewal, extra traffic) goes back to the agent, once.
            // (Traffic an agent bought is the main shop's sale, taken back above.)
            if (!$order->shop()->isMain() && $order->type !== OrderType::Traffic) {
                $this->pool->giveBack($order, $order->shop(), refunded: true);
            }
            // The cashback the order earned comes back out of what the refund gives — never more than it gives: a share
            // of it. A free order (a plan at no price) was paid nothing, and nothing goes back.
            $cashback = $this->cashback->takeBack($order);
            $back = Money::subtract($payment->amount, $cashback);
            if (Money::isPositive($back)) {
                $this->wallet->credit($customer, $back, WalletService::describeRefund($payment, $note, lessCashback: Money::isPositive($cashback)));
            }

            return true;
        });
    }

    /**
     * The order's open payment with that method (touched, so its expiry restarts) or a new one, under a lock on the order:
     * two taps make one payment.
     */
    private function openPayment(Order $order, PaymentMethod $method): Payment
    {
        return $this->db->transaction(function () use ($order, $method): Payment {
            if (Order::query()->whereKey($order->id)->lockForUpdate()->value('status') !== OrderStatus::Pending) {
                throw new OrderNotPayableException($order);
            }

            $open = $order->payments()->where('payment_method_id', $method->id)->where('status', PaymentStatus::Pending->value)->first();
            if ($open === null) {
                return $this->newPayment($order, $method);
            }
            $open->touch();

            return $open;
        });
    }

    /**
     * Takes a receipt (pending → awaiting review) with where its picture is, its cleaned file name and the customer's
     * words. One compare-and-swap: a cancel or another receipt that came first wins. Reported in the same transaction.
     *
     * @param array<string, mixed> $receipt
     */
    private function submit(Payment $payment, array $receipt, ?string $note, ?string $name): bool
    {
        $note = trim((string) $note);

        return $this->db->transaction(function () use ($payment, $receipt, $note, $name): bool {
            $submitted = Transitions::move($payment, 'status', [PaymentStatus::Pending], PaymentStatus::AwaitingReview, $receipt + [
                'receipt_name' => CustomerPictures::cleanName($name),
                'receipt_note' => $note !== '' ? mb_substr($note, 0, self::RECEIPT_NOTE_MAX) : null,
                'receipt_at' => now(),
            ]);
            if ($submitted) {
                $this->reports->receiptSubmitted($payment);
            }

            return $submitted;
        });
    }

    /** Drops the payment and, unless `$sparingReceipts` finds another receipt in review, its order and other open payments. */
    private function dropping(Payment $payment, string $reviewer, ?string $note, bool $sparingReceipts): CancelOutcome
    {
        return $this->db->transaction(function () use ($payment, $reviewer, $note, $sparingReceipts): CancelOutcome {
            if (!$this->drop($payment, $reviewer, $note)) {
                return CancelOutcome::Lost;
            }
            // Locked as they are read: a receipt sent for one of them in the same moment came first, or finds it cancelled.
            $open = array_map(static fn(PaymentStatus $status): string => $status->value, PaymentStatus::open());
            $others = $payment->order->payments()->whereKeyNot($payment->id)->whereIn('status', $open)->lockForUpdate()->get();
            if ($sparingReceipts && $others->contains(static fn(Payment $other): bool => $other->status === PaymentStatus::AwaitingReview)) {
                return CancelOutcome::Alone;
            }
            if (!$this->orders->cancel($payment->order, $note ?? OrderService::NOTE_CANCELLED_BY_SUPPORT)) {
                return CancelOutcome::Alone;
            }
            foreach ($others as $other) {
                $this->drop($other, $reviewer, $note);
            }

            return CancelOutcome::WithOrder;
        });
    }

    /** Drops an open payment with support's note (reported when it had a receipt). True when this call dropped it. */
    private function drop(Payment $payment, string $reviewer, ?string $note): bool
    {
        $dropped = Transitions::move($payment, 'status', PaymentStatus::open(), PaymentStatus::Cancelled, ['reviewer' => $reviewer, 'note' => $note]);
        if ($dropped) {
            $this->reports->receiptCancelled($payment, $note);
        }

        return $dropped;
    }

    private function newPayment(Order $order, PaymentMethod $method): Payment
    {
        return Payment::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'status' => PaymentStatus::Pending,
            'amount' => $order->amount,
        ]);
    }

    /**
     * Inside the caller's transaction: the gateway's part (the wallet's debit), the payment's move (to paid, or failed
     * with the gateway's reason), and once paid, the order's claim, the cancelling of its other open payments without a
     * receipt in review, and the receipt's report. Losing either compare-and-swap throws, rolling it all back.
     *
     * @param list<PaymentStatus> $from
     * @throws PaymentSettledException the payment was decided otherwise first
     * @throws OrderNotPayableException the order is not pending any more
     */
    private function settle(Payment $payment, GatewayInterface $gateway, ?string $reviewer, array $from): PaymentResult
    {
        $result = $gateway->settle($payment);
        if (!($result->success ? $this->markPaid($payment, $result, $reviewer, $from) : $this->markFailed($payment, $result, $reviewer))) {
            throw new PaymentSettledException($payment);
        }
        if (!$result->success) {
            return $result;
        }
        if (!$this->orders->markPaid($payment->order)) {
            throw new OrderNotPayableException($payment->order);
        }
        // Locked as they are read: a payment refused meanwhile keeps the reason its customer was told, never this word.
        foreach ($payment->order->payments()->whereKeyNot($payment->id)->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])->lockForUpdate()->get() as $left) {
            Transitions::move($left, 'status', [PaymentStatus::Pending, PaymentStatus::Failed], PaymentStatus::Cancelled, ['note' => $left->note ?? self::NOTE_PAID_OTHERWISE]);
        }
        $this->reports->receiptApproved($payment);

        return $result;
    }

    /**
     * After the settlement's commit: the referrer's commission, the delivery, then a referral's first-purchase gifts. A
     * failure of either referral step is logged and never affects the payment.
     */
    private function settled(Payment $payment): void
    {
        $this->logger->info('Payment {id} settled via {gateway} ({reference})', ['id' => $payment->id, 'gateway' => $payment->method->driver, 'reference' => $payment->reference]);
        try {
            $this->referrals->reward($payment);
        } catch (\Throwable $e) {
            $this->logger->error('The referral commission of payment {id} failed: {message}', ['id' => $payment->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
        $this->orders->deliver($payment->order);
        try {
            $this->referrals->purchased($payment);
        } catch (\Throwable $e) {
            $this->logger->error('The referral gifts of payment {id} failed: {message}', ['id' => $payment->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * The gateway said yes: one of `$from` → paid, with who decided; an earlier verdict's note is cleared.
     *
     * @param list<PaymentStatus> $from
     */
    private function markPaid(Payment $payment, PaymentResult $result, ?string $reviewer, array $from): bool
    {
        return Transitions::move($payment, 'status', $from, PaymentStatus::Paid, [
            'reference' => $result->reference,
            'reviewer' => $reviewer,
            'note' => null,
            'paid_at' => now(),
        ]);
    }

    /** The gateway said no (e.g. the wallet was spent meanwhile): the payment fails with its reason. */
    private function markFailed(Payment $payment, PaymentResult $result, ?string $reviewer): bool
    {
        return Transitions::move($payment, 'status', [PaymentStatus::Pending, PaymentStatus::AwaitingReview], PaymentStatus::Failed, [
            'reviewer' => $reviewer,
            'note' => $result->message,
        ]);
    }
}
