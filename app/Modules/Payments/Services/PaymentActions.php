<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Notifications\Enums\Delivery;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Payments\Contracts\RefundedOrders;
use App\Modules\Payments\Enums\CancelOutcome;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;

/**
 * Support's decisions about a payment, wherever they are made (the panels, the website's admins, the report group's
 * buttons, the auto-approve timer). Each checks its rule (allowed()) and who decides (never one's own payment), is one
 * compare-and-swap in PaymentService, and tells the customer only when this call decided; the loser of two decisions at
 * once is refused with DECIDED_MEANWHILE.
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
        private readonly RefundedOrders $refunded,
    ) {}

    /**
     * What may be done with the payment now:
     * - approve: an unpaid card payment (even a refused one — support may see the transfer after all) of an open order;
     * - reject: a receipt waiting for review;
     * - cancel: anything unpaid;
     * - remind: a card payment the customer never finished, of an open order;
     * - retry: the payment that paid an order whose delivery stalled (OrderActions::stalled());
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
     * Accepts a card payment: the order is delivered and the customer told. An admin never approves their own payment,
     * and the website's admins only a receipt still in review (approving without one would give a service away).
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

    /** Accepts a receipt whose review window ran out, with no reviewer's name; false when it was decided otherwise first. */
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
     * Refuses a receipt with the reason the customer reads (or none); the order stays open for them to pay again.
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
     * Drops an unpaid payment and its open order (PaymentService::cancel()); the customer is notified only when the order
     * went too.
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
     * Gives a paid payment back and tells the customer: what a delivered order gave a service goes first — a purchase's
     * service off its panel and the shop, a renewal's or an extra's term and traffic (RefundedOrders) —, then the money
     * (PaymentService::refund()), only while the order is in the state it was judged by: one delivered meanwhile is
     * refused, never refunded with what it gave. An admin never refunds their own.
     *
     * @throws ActorRefusedException 403 their own payment
     * @throws ValidationException 422 on `status`
     * @throws RefundRefusedException what the order put in the shop is no longer there, or its service cannot go
     * @throws DomainRuleException the service's change refused (busy, too many panel operations): nothing refunded
     */
    public function refund(Payment $payment, Actor $actor, ?string $note): Payment
    {
        $this->allow($payment, 'refund');
        $order = $payment->order;
        if ($actor->is($order->user_id)) {
            throw ActorRefusedException::ownPayment();
        }
        $state = $order->status;
        $removed = $state === OrderStatus::Fulfilled ? $this->refunded->takeBack($order, $actor) : null;
        if (!$this->payments->refund($payment, $actor->name(), $note, $state)) {
            throw ValidationException::on('status', self::DECIDED_MEANWHILE);
        }
        $this->notifier->paymentRefunded($payment, $removed);

        return $payment;
    }

    /**
     * Reminds the customer of a card payment they never finished; returns whether the message reached them.
     *
     * @throws ValidationException 422 on `status`
     */
    public function remind(Payment $payment): Delivery
    {
        $this->allow($payment, 'remind');

        return $this->notifier->paymentReminder($payment);
    }

    /**
     * Retries the delivery of the order the payment paid (OrderActions::retry()).
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
     * @throws ValidationException 422 on `status`, worded by the state the payment and its order are in
     */
    private function allow(Payment $payment, string $action): void
    {
        if (!$this->allowed($payment)[$action]) {
            throw ValidationException::on('status', $this->refusal($payment, $action));
        }
    }

    /**
     * Why an action is not allowed, worded by the state the payment and its order are in now (the report group's buttons
     * say it too). `receipt` words why a receipt is not taken (PaymentService::awaitsReceipt()).
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
