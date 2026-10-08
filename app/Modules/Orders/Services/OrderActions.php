<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\CancelOutcome;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Database\ConnectionInterface;

/**
 * What support does with an order, wherever they do it — the orders screen of either panel or of the shop's website,
 * the payments screen's retry, the report group's retry button — and what the scheduler does in their place (resume() a
 * delivery whose process died): each under its rule (allowed()), each telling the customer what they must hear, only
 * when this call is the one that did it. A surface renders its own answer and nothing more.
 */
final class OrderActions
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * What may be done with the order now:
     * - retry: it is stuck (stuck());
     * - cancel: nobody paid it.
     *
     * @return array{retry: bool, cancel: bool}
     */
    public function allowed(Order $order): array
    {
        return [
            'retry' => self::stuck($order),
            'cancel' => $order->status === OrderStatus::Pending,
        ];
    }

    /**
     * Paid, but not delivered — the queue that waits on support (Order::stuck(), the one rule for both): its delivery
     * stalled (stalled()) while the payment that paid it stands — a refunded one gave the money back. Its payments loaded.
     */
    public static function stuck(Order $order): bool
    {
        return self::stalled($order) && $order->paidBy() !== null;
    }

    /**
     * Its delivery came to nothing: it failed, or nobody is delivering it (OrderService::isStale()) — whatever came of its
     * payment since. A payment's own retry asks this of its order, the payment being the one that paid it (PaymentActions).
     */
    public static function stalled(Order $order): bool
    {
        return $order->status === OrderStatus::Failed || OrderService::isStale($order);
    }

    /**
     * Deliver a paid order again. The customer was told once that the delivery failed, so they hear again only when it
     * worked — about the payment that paid it, as a reply to its receipt. Answers that payment; the order is as the
     * delivery left it.
     *
     * @throws ValidationException 422 on `status`: not stuck, or another process is delivering it — or just did
     */
    public function retry(Order $order): Payment
    {
        $this->allow($order, 'retry');
        $payment = $order->paidBy() ?? throw new \LogicException("Order #{$order->id} has no payment that paid it.");

        if (!$this->orders->deliver($order)) {
            throw ValidationException::on('status', OrderService::DELIVERY_TAKEN);
        }
        $this->delivered($payment, $order);

        return $payment;
    }

    /**
     * Deliver what a process that died left undelivered — an order paid with nobody delivering it (OrderService::resume())
     * —, ResumeDeliveriesTask's. The customer hears as from a retry: only when it worked, about the payment that paid it;
     * a delivery that fails waits for support like any other, and the report group hears it. False when there was
     * nothing to resume: not stale, given back, or delivered by another process meanwhile.
     */
    public function resume(Order $order): bool
    {
        $payment = $order->paidBy();
        if ($payment === null || !$this->orders->resume($order)) {
            return false;
        }
        $this->delivered($payment, $order);

        return true;
    }

    /**
     * Drop an order nobody paid — through its newest open payment, which takes the order and its other open payments with
     * it, a receipt in review among them, support's note on each (PaymentService::cancelWithOrder()) —, or alone when the
     * customer never picked a way to pay. The customer hears about that payment (a reply to its receipt); an order they
     * never started paying goes quietly.
     *
     * @throws ValidationException 422 on `status`
     */
    public function cancel(Order $order, Actor $actor, ?string $note): void
    {
        $this->allow($order, 'cancel');

        $newest = $this->db->transaction(function () use ($order, $actor, $note): ?Payment {
            // Newest first: one decided otherwise in the same moment leaves it to the next.
            foreach ($order->payments as $payment) {
                if ($payment->status->isOpen() && $this->payments->cancelWithOrder($payment, $actor->name(), $note) !== CancelOutcome::Lost) {
                    return $payment;
                }
            }
            // None open: the customer never picked a way to pay — or each was decided otherwise in the same moment.
            $this->orders->cancel($order, $note ?? OrderService::NOTE_CANCELLED_BY_SUPPORT);

            return null;
        });

        $order->refresh();
        if ($newest !== null && $order->status === OrderStatus::Cancelled) {
            $this->notifier->orderCancelled($newest);
        }
    }

    /** A delivery this call did: the customer hears it only when it worked, as a reply to the receipt of what paid it. */
    private function delivered(Payment $payment, Order $order): void
    {
        // What the delivery made — the service — is read afresh, not the caller's copy from before it.
        $payment->setRelation('order', $order->refresh());
        if ($order->status === OrderStatus::Fulfilled) {
            $this->notifier->paymentSettled($payment);
        }
    }

    /**
     * @param 'retry'|'cancel' $action
     * @throws ValidationException when the order's state does not allow the action — worded by that state, which most
     *                             often was decided elsewhere a moment ago
     */
    private function allow(Order $order, string $action): void
    {
        if (!$this->allowed($order)[$action]) {
            throw ValidationException::on('status', $action === 'cancel' ? self::uncancellable($order) : self::unretriable($order));
        }
    }

    /** Why the order is not cancelled: somebody paid it, or it is over already. */
    private static function uncancellable(Order $order): string
    {
        return match ($order->status) {
            OrderStatus::Cancelled => 'این سفارش لغو شده است.',
            OrderStatus::Refunded => 'مبلغ این سفارش بازپرداخت شده است.',
            default => 'این سفارش پرداخت شده است و لغو نمی‌شود؛ برای برگرداندن مبلغ، پرداختش را بازپرداخت کنید.',
        };
    }

    /** Why nothing is delivered again: nothing is owed — or its delivery has not stalled. */
    private static function unretriable(Order $order): string
    {
        if (self::stalled($order)) {
            // Stalled and not stuck: what paid it was given back.
            return 'پرداخت این سفارش بازپرداخت شده است؛ چیزی برای تحویل نمانده.';
        }

        return match ($order->status) {
            OrderStatus::Pending => 'این سفارش هنوز پرداخت نشده است.',
            OrderStatus::Cancelled => 'این سفارش لغو شده است.',
            OrderStatus::Refunded => 'مبلغ این سفارش بازپرداخت شده است.',
            OrderStatus::Fulfilled => 'این سفارش تحویل شده است.',
            default => 'تحویل این سفارش در جریان است؛ اگر تا چند دقیقه دیگر تمام نشد، دوباره امتحان کنید.',
        };
    }
}
