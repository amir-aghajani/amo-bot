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
use App\Modules\Payments\Enums\GatewayKind;
use App\Modules\Payments\Enums\OnlineState;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayUnavailableException;
use App\Modules\Payments\Exceptions\RefundRefusedException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Services\ReferralService;

/**
 * Support's decisions about a payment, wherever they are made (the panels, the website's admins, the report group's
 * buttons, the auto-approve timer). Each checks its rule (allowed()) and who decides (never one's own payment), is one
 * compare-and-swap in PaymentService, and tells the customer only when this call decided; the loser of two decisions at
 * once is refused with DECIDED_MEANWHILE. An online payment is never approved nor rejected by hand: support asks its
 * gateway (check()).
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
        private readonly OnlinePayments $online,
        private readonly ReferralService $referrals,
    ) {}

    /**
     * What may be done with the payment now:
     * - approve: an unpaid card payment (even a refused one — support may see the transfer after all) of an open order;
     * - reject: a receipt waiting for review;
     * - cancel: anything unpaid;
     * - remind: a card payment the customer never finished, of an open order;
     * - retry: the payment that paid an order whose delivery stalled (OrderActions::stalled());
     * - refund: a paid payment whose order's delivery is not under way;
     * - check: an online payment opened on its gateway and not paid — waiting, its page closed, or cancelled —, whose
     *   gateway may have taken the money all the same (check()); never one held for support (OnlinePayments::isHeld()).
     * One that went to the wallet once its order had closed (Payment::wentToWallet()) paid nothing of the order: its
     * money is the customer's, nothing to retry or refund.
     *
     * @return array{approve: bool, reject: bool, cancel: bool, remind: bool, retry: bool, refund: bool, check: bool}
     */
    public function allowed(Payment $payment): array
    {
        $order = $payment->order;
        $orderOpen = $order->status === OrderStatus::Pending;
        $card = $this->gateways->isManual($payment->method);
        $unpaid = $payment->status->isOpen();
        $paid = $payment->isPaid() && !$payment->wentToWallet();

        return [
            'approve' => $card && $unpaid && $orderOpen,
            'reject' => $payment->status === PaymentStatus::AwaitingReview,
            'cancel' => $unpaid,
            'remind' => $card && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true) && $orderOpen,
            'retry' => $paid && OrderActions::stalled($order),
            'refund' => $paid && in_array($order->status, [OrderStatus::Paid, OrderStatus::Failed, OrderStatus::Fulfilled], true),
            'check' => $this->isOnline($payment) && $payment->link !== null && !OnlinePayments::isHeld($payment)
                && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed, PaymentStatus::Cancelled], true),
        ];
    }

    /**
     * Accepts a card payment: the order is delivered and the customer told. An admin never approves their own payment,
     * and the website's admins only a receipt still in review (approving without one would give a service away). One
     * judged as a receipt in review — always so on the website and by the review window — is approved only while it
     * still is: a rejection or a cancel in the same moment stands, never approved over.
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
        if (!$this->payments->approve($payment, $actor->reviewer, receiptOnly: $payment->status === PaymentStatus::AwaitingReview)) {
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
     * refused, never refunded with what it gave. A top-up's refund takes back the commission it earned the customer's
     * referrer, and the referrer is told (ReferralService::refundTakesBack(); one they spent refuses the refund, nothing
     * taken); any other refund's money stays in the shop, and its commission stands. An admin never refunds their own.
     *
     * @throws ActorRefusedException 403 their own payment
     * @throws ValidationException 422 on `status`
     * @throws RefundRefusedException what the order put in the shop is no longer there, its service cannot go, or the
     *                                referrer spent a top-up's commission
     * @throws DomainRuleException the service's change refused (busy, too many panel operations): nothing refunded
     */
    public function refund(Payment $payment, Actor $actor, ?string $note): Payment
    {
        $this->allow($payment, 'refund');
        $order = $payment->order;
        if ($actor->is($order->user_id)) {
            throw ActorRefusedException::ownPayment();
        }
        // Read before the refund deletes it, for the notice: a top-up has no service to take back first, so the refund's
        // own transaction is where a commission spent refuses it.
        $commission = ReferralService::refundTakesBack($order) ? $this->referrals->commissionOf($payment) : null;
        $state = $order->status;
        $removed = $state === OrderStatus::Fulfilled ? $this->refunded->takeBack($order, $actor) : null;
        if (!$this->payments->refund($payment, $actor->name(), $note, $state)) {
            throw ValidationException::on('status', self::DECIDED_MEANWHILE);
        }
        $this->notifier->paymentRefunded($payment, $removed);
        if ($commission !== null) {
            $this->notifier->commissionTakenBack($commission);
        }

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
     * Asks an online payment's gateway what came of it (OnlinePayments::check()): paid, it is settled and the customer
     * told; closed, it fails. The gateway's word alone moves it. `$by`: who of support asked — the report of a hold the
     * answer causes names them.
     *
     * @throws ValidationException 422 on `status`
     * @throws GatewayUnavailableException the gateway could not say — nothing changed —, or its answer held it (`held`)
     */
    public function check(Payment $payment, ?Actor $by = null): OnlineState
    {
        $this->allow($payment, 'check');

        return $this->online->check($payment, $by?->reviewer);
    }

    /**
     * @param 'approve'|'reject'|'cancel'|'remind'|'retry'|'refund'|'check' $action
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
     * @param 'approve'|'reject'|'cancel'|'remind'|'retry'|'refund'|'check'|'receipt' $action
     */
    public function refusal(Payment $payment, string $action): string
    {
        $status = $payment->status;
        $order = $payment->order;
        $online = $this->isOnline($payment);

        // Held for support — what its gateway said does not fit it, it kept the money unsettled, or closed it with money
        // in —: its own note says which.
        if ($action === 'check' && OnlinePayments::isHeld($payment)) {
            return (in_array($payment->note, PaymentService::HELD_NOTES, true) ? $payment->note : PaymentService::NOTE_HELD) . '.';
        }
        // Unpaid, and no online payment opened on its gateway: nothing to ask it.
        if ($action === 'check' && !$status->isSettled()) {
            return $online ? 'این پرداخت در درگاه باز نشد؛ چیزی برای استعلام ندارد.' : 'استعلام از درگاه فقط برای پرداخت آنلاین است.';
        }
        if ($status === PaymentStatus::Paid) {
            return match (true) {
                $action === 'cancel' => 'این پرداخت انجام شده است و لغو نمی‌شود؛ برای برگرداندن مبلغ، بازپرداختش کنید.',
                in_array($action, ['remind', 'check'], true) => 'این پرداخت انجام شده است.',
                in_array($action, ['retry', 'refund'], true) && $payment->wentToWallet() => 'این پرداخت بعد از بسته شدن سفارشش رسید و مبلغش به کیف پول مشتری رفت؛ نه تحویلی دارد نه بازپرداختی.',
                $action === 'retry' => $order->status === OrderStatus::Fulfilled
                    ? 'سفارش این پرداخت تحویل شده است.'
                    : 'تحویل سفارش این پرداخت در جریان است؛ اگر تا چند دقیقه دیگر تمام نشد، دوباره امتحان کنید.',
                $action === 'refund' => PaymentService::DELIVERY_RUNNING,
                default => 'این پرداخت تایید شده است.',
            };
        }
        if (!$status->isOpen()) {
            return self::CLOSED[$status->value];
        }

        // Open — pending, a receipt in review, refused —, which anyone may cancel: the money is not in.
        return match ($action) {
            'reject' => match (true) {
                $online => 'پرداخت آنلاین رسیدی ندارد؛ نتیجه‌اش را درگاه می‌گوید.',
                $status === PaymentStatus::Pending => 'رسید این پرداخت هنوز نرسیده است.',
                default => self::failed($payment),
            },
            'retry', 'refund' => 'این پرداخت انجام نشده است.',
            default => match (true) {
                !$this->gateways->isManual($payment->method) => match ($action) {
                    'remind' => 'یادآوری فقط برای پرداخت کارت به کارت است.',
                    'receipt' => 'رسید فقط برای پرداخت کارت به کارت فرستاده می‌شود.',
                    default => $online ? 'پرداخت آنلاین تایید دستی ندارد؛ نتیجه‌اش را درگاه می‌گوید.' : 'پرداخت از کیف پول تایید دستی ندارد.',
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

    /** Made on an online gateway (GatewayKind::Online): paid on its page, and only its word settles it. */
    private function isOnline(Payment $payment): bool
    {
        return $this->gateways->kind($payment->method->driver) === GatewayKind::Online;
    }
}
