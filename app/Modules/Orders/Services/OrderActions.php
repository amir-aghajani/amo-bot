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
 * Support's operations on an order — retry a stuck delivery, cancel an unpaid order — wherever they are made, and the
 * scheduler's resume() of a delivery whose process died. Each checks its rule (allowed()) and tells the customer only
 * when this call did the work.
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
     * Paid but not delivered (Order::stuck() is the same rule as a query): the delivery stalled while the paying payment
     * still stands. Its payments must be loaded.
     */
    public static function stuck(Order $order): bool
    {
        return self::stalled($order) && $order->paidBy() !== null;
    }

    /** Its delivery failed, or nobody is delivering it (OrderService::isStale()), whatever happened to the payment since. */
    public static function stalled(Order $order): bool
    {
        return $order->status === OrderStatus::Failed || OrderService::isStale($order);
    }

    /**
     * Delivers a paid order again and returns the payment that paid it. The customer heard of the failure once, so they
     * are notified again only when it worked.
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
     * Delivers an order a dead process left undelivered (ResumeDeliveriesTask); the customer is notified only when it worked.
     * False when there was nothing to resume (not stale, refunded, or delivered meanwhile).
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
     * Cancels an unpaid order through its newest open payment (PaymentService::cancelWithOrder()), or alone when no
     * payment was ever started. The customer is notified about that payment; an order never paid for goes quietly.
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

    /** After a delivery this call did: tells the customer when it worked (a reply to the paying payment's receipt). */
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
     * @throws ValidationException 422 on `status`, worded by the order's state
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
