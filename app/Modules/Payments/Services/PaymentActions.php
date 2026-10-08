<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Payments\Enums\CancelOutcome;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;

/**
 * What support — or the review window's timer — decides about a payment, wherever it is decided: the payments screen
 * of either panel or of the shop's website (its admins), the report group's buttons, AutoApproveReceiptsTask. Each
 * operation is held to its rule (allowed()) and to who decides it (the Actor: never one's own payment approved or given
 * back), is one compare-and-swap in PaymentService, and tells the customer only when this call is the one that decided —
 * of two decisions in the same moment, the one that lost is refused with DECIDED_MEANWHILE and tells nobody. A surface
 * renders its own answer and nothing more.
 */
final class PaymentActions
{
    /** The rule allowed it when it was read, but it was decided otherwise before this took effect. */
    public const DECIDED_MEANWHILE = 'این پرداخت همین حالا به شکل دیگری تعیین تکلیف شد.';

    /** What a payment closed for good came to, when that is why an operation is refused. */
    private const CLOSED = [
        'cancelled' => 'این پرداخت لغو شده است.',
        'refunded' => 'این پرداخت بازپرداخت شده است.',
    ];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderActions $orders,
        private readonly GatewayRegistry $gateways,
        private readonly CustomerNotifier $notifier,
    ) {}

    /**
     * What may be done with the payment now:
     * - approve: a card payment whose money is not in — pending, waiting for review, or refused (support may see the
     *   transfer after all) — while its order is open;
     * - reject: a receipt waiting for review;
     * - cancel: anything unpaid, with its open order;
     * - remind: a card payment the customer never finished, while its order is open;
     * - retry: the payment that paid its order, standing (paid), while the order's delivery stalled
     *   (OrderActions::stalled()) — its order's OrderActions::stuck(), read off the payment;
     * - refund: a paid payment whose order's delivery is not under way.
     *
     * @return array{approve: bool, reject: bool, cancel: bool, remind: bool, retry: bool, refund: bool}
     */
    public function allowed(Payment $payment): array
    {
        $order = $payment->order;
        $orderOpen = $order->status === OrderStatus::Pending;
        $card = $this->gateways->isManual($payment->method);
        $unpaid = $payment->status->isOpen();

        return [
            'approve' => $card && $unpaid && $orderOpen,
            'reject' => $payment->status === PaymentStatus::AwaitingReview,
            'cancel' => $unpaid,
            'remind' => $card && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true) && $orderOpen,
            'retry' => $payment->isPaid() && OrderActions::stalled($order),
            'refund' => $payment->isPaid() && in_array($order->status, [OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Fulfilled], true),
        ];
    }

    /**
     * Accept a card payment — its receipt, or the transfer support saw some other way: the order is delivered, and the
     * customer gets what it brought (or hears that the delivery failed and support finishes it). One of the shop's admins
     * — its customer too — never approves their own payment; from the website, only a receipt in review (approved there
     * without one, a service is given away) — still in review as it is approved: one another admin rejected in the same
     * moment is not approved after all.
     *
     * @param Actor $actor A panel's principal, one of the shop's admins on its website or in its report group, or the
     *                     review window's timer (autoApprove())
     * @throws ActorRefusedException 403 their own payment; on the website, no receipt in review
     * @throws ValidationException 422 on `status`: not allowed, or decided otherwise in the same moment
     * @throws OrderNotPayableException its order was paid another way or cancelled in the same moment
     */
    public function approve(Payment $payment, Actor $actor): Payment
    {
        $this->allow($payment, 'approve');
        if ($actor->is($payment->order->user_id)) {
            throw ActorRefusedException::ownPayment();
        }
        if ($actor->isStaff() && $payment->status !== PaymentStatus::AwaitingReview) {
            throw ActorRefusedException::receiptFirst();
        }
        if (!$this->payments->approve($payment, $actor->reviewer, receiptOnly: $actor->isStaff())) {
            throw ValidationException::on('status', self::DECIDED_MEANWHILE);
        }
        $this->notifier->paymentSettled($payment);

        return $payment;
    }

    /**
     * The method's review window ran out with nobody looking: accepted as support would have, with nobody's name on it
     * (Actor::system()). False when it may not be any more — decided, or its order closed — or another decision came first.
     */
    public function autoApprove(Payment $payment): bool
    {
        try {
            $this->approve($payment, Actor::system());
        } catch (ValidationException|OrderNotPayableException) {
            return false;
        }

        return true;
    }

    /**
     * Refuse a receipt, with the reason the customer reads (or none); the order stays open for them to pay again.
     *
     * @throws ValidationException 422 on `status`
     */
    public function reject(Payment $payment, Actor $actor, ?string $note): Payment
    {
        $this->allow($payment, 'reject');
        if (!$this->payments->reject($payment, $actor->name(), $note)) {
            throw ValidationException::on('status', self::DECIDED_MEANWHILE);
        }
        $this->notifier->paymentRejected($payment);

        return $payment;
    }

    /**
     * Drop an unpaid payment and its open order (PaymentService::cancel()); the customer hears it — not about a stale
     * payment of an order paid another way, nor about one whose order waits on another payment's receipt in review: each
     * is tidied away quietly, alone.
     *
     * @throws ValidationException 422 on `status`
     */
    public function cancel(Payment $payment, Actor $actor, ?string $note): Payment
    {
        $this->allow($payment, 'cancel');
        $outcome = $this->payments->cancel($payment, $actor->name(), $note);
        if ($outcome === CancelOutcome::Lost) {
            throw ValidationException::on('status', self::DECIDED_MEANWHILE);
        }
        if ($outcome === CancelOutcome::WithOrder) {
            $this->notifier->orderCancelled($payment);
        }

        return $payment;
    }

    /**
     * Give a paid payment back (PaymentService::refund() says how, by what it bought) and tell the customer. One of the
     * shop's admins never gives their own back.
     *
     * @throws ActorRefusedException 403 their own payment
     * @throws ValidationException 422 on `status`
     * @throws RefundRefusedException what the order put in the shop is no longer there
     */
    public function refund(Payment $payment, Actor $actor, ?string $note): Payment
    {
        $this->allow($payment, 'refund');
        if ($actor->is($payment->order->user_id)) {
            throw ActorRefusedException::ownPayment();
        }
        if (!$this->payments->refund($payment, $actor->name(), $note)) {
            throw ValidationException::on('status', self::DECIDED_MEANWHILE);
        }
        $this->notifier->paymentRefunded($payment);

        return $payment;
    }

    /**
     * Nudge the customer about a card payment they never finished: send the receipt, or pay again. Telling them is all
     * it does, so the answer is whether it did.
     *
     * @throws ValidationException 422 on `status`
     */
    public function remind(Payment $payment): Delivery
    {
        $this->allow($payment, 'remind');

        return $this->notifier->paymentReminder($payment);
    }

    /**
     * Deliver again what the payment paid for — the order's retry (OrderActions::retry()), from the payments screen.
     *
     * @throws ValidationException 422 on `status`
     */
    public function retry(Payment $payment): Payment
    {
        $this->allow($payment, 'retry');
        $this->orders->retry($payment->order);

        return $payment;
    }

    /**
     * @param 'approve'|'reject'|'cancel'|'remind'|'retry'|'refund' $action
     * @throws ValidationException when the payment's state does not allow the action — worded by the state the payment and
     *                             its order are in, which most often was decided elsewhere a moment ago
     */
    private function allow(Payment $payment, string $action): void
    {
        if (!$this->allowed($payment)[$action]) {
            throw ValidationException::on('status', $this->refusal($payment, $action));
        }
    }

    /**
     * Why an action allowed() does not allow is refused, by the state the payment and its order are in now — the report
     * group's buttons say it too; and why a receipt is not taken for it (`receipt`: it awaits none —
     * PaymentService::awaitsReceipt()), which the website's upload says in the same words.
     *
     * @param 'approve'|'reject'|'cancel'|'remind'|'retry'|'refund'|'receipt' $action
     */
    public function refusal(Payment $payment, string $action): string
    {
        $status = $payment->status;
        $order = $payment->order;

        if ($status === PaymentStatus::Paid) {
            return match ($action) {
                'cancel' => 'این پرداخت انجام شده است و لغو نمی‌شود؛ برای برگرداندن مبلغ، بازپرداختش کنید.',
                'remind' => 'این پرداخت انجام شده است.',
                'retry' => $order->status === OrderStatus::Fulfilled
                    ? 'سفارش این پرداخت تحویل شده است.'
                    : 'تحویل سفارش این پرداخت در جریان است؛ اگر تا چند دقیقه دیگر تمام نشد، دوباره امتحان کنید.',
                'refund' => PaymentService::DELIVERY_RUNNING,
                default => 'این پرداخت تایید شده است.',
            };
        }
        if (!$status->isOpen()) {
            return self::CLOSED[$status->value];
        }

        // Open — pending, a receipt in review, refused —, which anyone may cancel: the money is not in.
        return match ($action) {
            'reject' => $status === PaymentStatus::Pending ? 'رسید این پرداخت هنوز نرسیده است.' : self::failed($payment),
            'retry', 'refund' => 'این پرداخت انجام نشده است.',
            default => match (true) {
                !$this->gateways->isManual($payment->method) => match ($action) {
                    'remind' => 'یادآوری فقط برای پرداخت کارت به کارت است.',
                    'receipt' => 'رسید فقط برای پرداخت کارت به کارت فرستاده می‌شود.',
                    default => 'پرداخت از کیف پول تایید دستی ندارد.',
                },
                in_array($action, ['remind', 'receipt'], true) && $status === PaymentStatus::AwaitingReview => 'رسید این پرداخت رسیده و در انتظار بررسی است.',
                $action === 'receipt' && $status === PaymentStatus::Failed => self::failed($payment),
                $order->status === OrderStatus::Cancelled => 'سفارش این پرداخت لغو شده است.',
                default => 'سفارش این پرداخت با پرداخت دیگری پرداخت شده است.',
            },
        };
    }

    /** A payment that failed: its receipt refused, or — none sent — its gateway said no. */
    private static function failed(Payment $payment): string
    {
        return $payment->receipt_at !== null ? 'رسید این پرداخت رد شده است.' : 'این پرداخت ناموفق بوده است.';
    }
}
