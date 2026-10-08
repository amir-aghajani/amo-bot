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
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Exceptions\InsufficientBalanceException;
use App\Modules\Users\Services\CustomerPictures;
use App\Modules\Users\Services\WalletService;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The money: payments made through a gateway and settled, handing paid orders to OrderService. Every move of a payment
 * is a compare-and-swap on `payments.status` (Transitions) that answers whether this call made it: of an admin's
 * approve and the auto-approve timer, the screen and the report group, two refund clicks, exactly one takes effect, and
 * only that one goes on to tell anyone (PaymentActions). A card-to-card receipt — sent in the bot or uploaded from the
 * website —, and what was decided about it, are reported to the admins' report group (ShopReports) by the call that
 * decided.
 */
final class PaymentService
{
    /** A refund asked while the delivery it would undo is under way. */
    public const DELIVERY_RUNNING = 'تحویل این سفارش همین حالا در جریان است؛ کمی بعد دوباره امتحان کنید.';

    /**
     * `payments.note` of a way of paying the customer left behind once the order was paid with another — unless it has a
     * word of its own already: a refused receipt keeps its reason.
     */
    public const NOTE_PAID_OTHERWISE = 'سفارش با روش دیگری پرداخت شد';

    /** The longest words a receipt keeps (`receipt_note`): a caption in the bot is cut to it, a website's note held to it. */
    public const RECEIPT_NOTE_MAX = 1024;

    /**
     * The longest note support gives a refund: a ledger line's (Ledger::NOTE_MAX), since the wallet line the refund writes
     * carries it (WalletService::describeRefund(), describeTopUpRefund()).
     */
    public const REFUND_NOTE_MAX = Ledger::NOTE_MAX;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly OrderService $orders,
        private readonly WalletService $wallet,
        private readonly TrafficPool $pool,
        private readonly ReferralService $referrals,
        private readonly ShopReports $reports,
        private readonly Receipts $receipts,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Start paying an open order with a method that may pay it (PaymentMethods::pays()). An instant gateway (the wallet)
     * settles at once: its payment is made, charged and settled — the order paid — in one transaction, then delivered; one
     * that loses the order to another payment in the same moment leaves nothing behind. A card transfer waits for its
     * receipt: the order's open payment with that card — the customer came back to it — or a new one.
     *
     * @return array{payment: Payment, initiation: PaymentInitiation}
     * @throws OrderNotPayableException the order is not open any more; nothing was written
     */
    public function createForOrder(Order $order, PaymentMethod $method): array
    {
        if (!$method->enabled || !PaymentMethods::pays($method, $order->type)) {
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
     * Accept a manual payment — its receipt, or the money seen some other way —: by a person (`$reviewer`: the panel login
     * or a bot admin), or by its method's review window running out (null — which is what makes it the timer's,
     * Payment::wasAutoApproved()). `$receiptOnly`: only while its receipt waits for review — the website's admins approve
     * nothing else —, so a receipt rejected or cancelled in the same moment is not approved after all. The payment's move
     * and the order's claim (pending → paid) are one transaction, the delivery comes after the commit: it talks to a
     * panel and must not hold a transaction open. True when this call settled it; false when it was decided otherwise
     * first.
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
     * Whether the payment waits for its receipt: a card transfer's (a manual gateway's), pending — no receipt sent yet,
     * none refused, nothing decided —, while its order is open. The one rule the bot (ReceiptHandler) and the website
     * (the Store API's upload) take a receipt by; of two receipts in the same moment, the compare-and-swap that takes one
     * (submitReceipt(), submitUpload()) decides.
     */
    public function awaitsReceipt(Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $this->gateways->isManual($payment->method)
            && $payment->order->status === OrderStatus::Pending;
    }

    /**
     * The customer sent the bot the receipt of a card payment: pending → awaiting review, with what they sent — the
     * picture's Telegram file id, its name when sent as a file, their caption, and their message, the one every word
     * about the verdict replies to. True when this call took it (submit()).
     */
    public function submitReceipt(Payment $payment, string $fileId, ?string $note, ?string $name, ?int $messageId): bool
    {
        return $this->submit($payment, ['receipt_file_id' => $fileId, 'receipt_message_id' => $messageId], $note, $name);
    }

    /**
     * The customer uploaded the receipt of a card payment from the shop's website: pending → awaiting review, with the
     * picture the shop keeps (`$path`, its name among the uploads — Receipts::keep()), its file name as their device gave
     * it, and their words. True when this call took it (submit()); the caller lets go of its picture otherwise.
     */
    public function submitUpload(Payment $payment, string $path, ?string $note, ?string $name): bool
    {
        return $this->submit($payment, ['receipt_path' => $path], $note, $name);
    }

    /**
     * Refuse a receipt: awaiting review → failed, with the reviewer's reason (or none) as its note — and its verdict to the
     * report group, in the same transaction. The order stays open: the customer may pay again. True when this call
     * decided it.
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
     * Support drops an unpaid payment — a receipt in review too (the payments screen's cancel) — and, while nothing else
     * paid it, its order with it: every other way of paying that order still open goes too, with the same word — an
     * order dropped leaves no payment open. Not while another payment of the order waits on its receipt's review, though:
     * that receipt may be the money (the customer gave up on this way and paid another), so this payment goes alone, and
     * the order and that receipt are left for support to decide. One transaction; each payment that had a receipt is
     * told to the report group.
     */
    public function cancel(Payment $payment, string $reviewer, ?string $note): CancelOutcome
    {
        return $this->dropping($payment, $reviewer, $note, sparingReceipts: true);
    }

    /**
     * The orders screen's cancel (OrderActions::cancel()), through the order's newest open payment: it, the order and
     * every other way of paying the order still open — a receipt in review among them, which the screen's confirmation
     * counts — dropped with support's word. One transaction; each payment that had a receipt is told to the report group.
     */
    public function cancelWithOrder(Payment $payment, string $reviewer, ?string $note): CancelOutcome
    {
        return $this->dropping($payment, $reviewer, $note, sparingReceipts: false);
    }

    /**
     * An order the customer walked away from — nothing happened to it, nor to its payments, since `$idleSince`
     * (ExpireOrdersTask) — cancelled with its unpaid payments, quietly, in one transaction. Not while a receipt of it
     * waits for support: that is support's to decide. Under the order's lock and its payments', so a receipt or a payment
     * arriving in the same moment either comes first and keeps it, or finds it cancelled. A receipt uploaded from the
     * website for one of those payments — one support refused — goes with it once that is committed: nobody reviews a
     * walked-away order's receipt again (Receipts::discard()). True when this call cancelled it.
     */
    public function expire(Order $order, \DateTimeInterface $idleSince): bool
    {
        $uploads = [];
        $expired = $this->db->transaction(function () use ($order, $idleSince, &$uploads): bool {
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
                if (Transitions::move($payment, 'status', [PaymentStatus::Pending, PaymentStatus::Failed], PaymentStatus::Cancelled, ['note' => $payment->note ?? OrderService::NOTE_EXPIRED]) && $payment->receipt_path !== null) {
                    $uploads[] = $payment->receipt_path;
                }
            }

            return Transitions::move($order, 'status', [OrderStatus::Pending], OrderStatus::Cancelled, ['notes' => OrderService::NOTE_EXPIRED]);
        });
        if ($expired) {
            $this->receipts->discard(...$uploads);
        }

        return $expired;
    }

    /**
     * Give a paid payment back — in one transaction, by what its order bought, only for the call that wins the payment:
     * - a purchase, a renewal: the amount goes to the customer's wallet; a service it delivered stays (support switches
     *   it off or deletes it from its screen);
     * - an agent's traffic: the amount goes to their wallet, and the traffic it added comes back out of their pool —
     *   refused while the pool no longer has it, sold since;
     * - a wallet top-up: support gives the money back outside the shop, so what the top-up put in the wallet comes
     *   back out — refused while the wallet no longer covers it (an agent's credit counting), spent since.
     * The order becomes refunded whether its delivery went through or failed; one under way refuses the refund until
     * it is over. A referrer's commission on the payment stands. True when this call refunded it.
     *
     * @throws RefundRefusedException what the order put in the shop is no longer there
     * @throws ValidationException 422 on `status`: the order's delivery is under way
     */
    public function refund(Payment $payment, string $reviewer, ?string $note): bool
    {
        try {
            return $this->refundWithin($payment, $reviewer, $note);
        } catch (RefundRefusedException|ValidationException $e) {
            // The rollback took the moves back; the models must not go on believing them.
            $payment->refresh();
            $payment->order->refresh();

            throw $e;
        }
    }

    private function refundWithin(Payment $payment, string $reviewer, ?string $note): bool
    {
        return $this->db->transaction(function () use ($payment, $reviewer, $note): bool {
            $order = $payment->order;
            // The order first, under a lock: a delivery claiming it now waits for this transaction, then finds it refunded.
            $state = Order::query()->whereKey($order->id)->lockForUpdate()->value('status');
            if (!Transitions::move($payment, 'status', [PaymentStatus::Paid], PaymentStatus::Refunded, ['reviewer' => $reviewer, 'note' => $note])) {
                return false;
            }
            $delivered = $state === OrderStatus::Fulfilled;
            if (!in_array($state, [OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Fulfilled], true) || !Transitions::move($order, 'status', [$state], OrderStatus::Refunded)) {
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
            $this->wallet->credit($customer, $payment->amount, WalletService::describeRefund($payment, $note));

            return true;
        });
    }

    /**
     * The order's open payment with that card — the customer came back to it, and the expiry counts from now — or a new
     * one, under a lock on the order: two taps make one.
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
     * A receipt taken: pending → awaiting review, with where its picture is (`$receipt`: a Telegram file id and the
     * message every word about the verdict replies to, or an upload of the shop's own), its file name as the customer's
     * device gave it (CustomerPictures::cleanName()), their words with it (cut to RECEIPT_NOTE_MAX) and when. One
     * compare-and-swap: a cancel, or another receipt, that came first wins and this one changes nothing. True when this
     * call took it; the receipt goes to the report group in the same transaction, and its review window starts
     * (AutoApproveReceiptsTask).
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

    /**
     * The payment dropped — then, unless `$sparingReceipts` finds another of the order's payments waiting on its
     * receipt's review, its order (still open) and the order's other open payments with it — in one transaction.
     */
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

    /** An open payment dropped with support's word — told to the report group when it had a receipt. True when this call dropped it. */
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
     * Inside the caller's transaction: the gateway's part (the wallet's debit), the payment's move — to paid from one of
     * `$from`, or failed with the gateway's reason — and, paid, the order's claim (pending → paid), the other ways of
     * paying it the customer left behind without a receipt going with it (a receipt in review stays support's), and the
     * receipt's verdict to the report group. Lost, either unwinds the transaction.
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
     * After the settlement's commit: what the money earns the customer's referrer — once, this call having won the
     * payment; a failure there is logged and never stands in the payment's way — and the delivery.
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
    }

    /**
     * The gateway said yes: one of `$from` (any open state — pending, awaiting review, failed — or a receipt in review
     * alone) → paid, with who decided; a word on an earlier verdict goes.
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

    /** The gateway said no (an insufficient wallet, a refused callback): the payment fails with its reason. */
    private function markFailed(Payment $payment, PaymentResult $result, ?string $reviewer): bool
    {
        return Transitions::move($payment, 'status', [PaymentStatus::Pending, PaymentStatus::AwaitingReview], PaymentStatus::Failed, [
            'reviewer' => $reviewer,
            'note' => $result->message,
        ]);
    }
}
