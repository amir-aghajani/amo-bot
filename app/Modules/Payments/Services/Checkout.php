<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\Shortfall;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Users\Models\User;
use App\Support\Money;

/**
 * The checkout every door goes through — the bot's (Telegram\Handlers\CheckoutScreen) and the shop's website's (the Store
 * API): something not ordered yet, at its price, and the way to pay it the customer picked. A wallet that cannot cover it
 * says so and nothing is ordered (shortfall()); otherwise the order is made — or the open one of the same thing found
 * (OrderService::open*()) — and paid (PaymentService::createForOrder()): the wallet settles at once, the order as it
 * ended — delivered, or failed and waiting for support's retry —, a card transfer waits for its receipt. A door renders
 * what came of it (CheckoutResult) and nothing more.
 *
 * A request made again with its key (the website's Idempotency-Key, `request_keys`) is answered by the order that key
 * came to — made, or found open and paid —, as it stands: never a second order, never a second charge (replayed()), and
 * never paid another way — the key keeps the way to pay it was first sent with, and another under it is refused; one
 * whose order was cancelled since — by support, or expired unpaid — is said so (cancelled). Two made in the same moment
 * make one order and get one answer: the key's unique row and the order's compare-and-swap decide which pays, and the
 * other describes it.
 *
 * In Payments rather than Orders: it is how an order gets paid — the wallet's shortfall, a gateway's next step — over
 * PaymentService, which already hands paid orders to OrderService; Orders depends on no service of Payments.
 */
final class Checkout
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
        private readonly GatewayRegistry $gateways,
    ) {}

    /**
     * What the wallet lacks to pay `$price` — an agent's credit counting —, with its balance; null when it can pay it, and
     * for any other way to pay (only the wallet is paid out of the customer's own money at once).
     */
    public function shortfall(User $user, PaymentMethod $method, string $price): ?Shortfall
    {
        if (!$method->isWallet()) {
            return null;
        }
        $balance = $user->balance();
        $spendable = Money::add($balance, $user->credit());
        if (Money::compare($spendable, $price) >= 0) {
            return null;
        }

        return new Shortfall($balance, Money::normalize($price), Money::subtract($price, Money::isPositive($spendable) ? $spendable : 0));
    }

    /**
     * Pay `$price` with `$method` for what `$open` orders — the order made, or the open one of the same thing found; with
     * `$key`, the order that key came to (OrderService::open*(), the key kept with `$method`):
     * - made again with its key — and its way to pay —, the order it came to answers as it stands once it may not be paid
     *   any more (replayed()), and while it may, it is what is paid — at its own amount, the price it was made at;
     * - the wallet that cannot cover it is short: nothing is ordered;
     * - `$guard` — the door's own hold, asked once nothing else stands in the way (the bot's: a checkout's wallet pays
     *   once) — refusing it closes the checkout: nothing ordered, nothing charged;
     * - an order paid or closed elsewhere in the same moment is closed — nothing charged —, or, made with a key, the
     *   order that key made as it stands (the same request made twice at once: the other paid it).
     *
     * @param \Closure(?OrderKey): Order $open
     * @param (\Closure(): bool)|null $guard
     * @throws RequestKeyReusedException 422: the key came to an order of something else, or was first sent with another way to pay
     * @throws TooManyUnpaidOrdersException 422: the website's request would leave its customer too many unpaid orders
     * @throws RenewalUnderWayException a renewal of the service is under way already (none made with this key)
     */
    public function pay(User $user, PaymentMethod $method, string $price, \Closure $open, ?string $key = null, ?\Closure $guard = null): CheckoutResult
    {
        $request = $key === null ? null : new OrderKey($key, $method->id);
        $order = $request === null ? null : $this->keyedOrder($user, $request, $open);
        if ($order !== null && !self::payable($order)) {
            return $this->standing($order, madeAgain: true);
        }

        $shortfall = $this->shortfall($user, $method, $order === null ? $price : $order->amount);
        if ($shortfall !== null) {
            return CheckoutResult::short($shortfall);
        }
        if ($guard !== null && !$guard()) {
            return CheckoutResult::closed();
        }

        try {
            $order ??= $open($request);
            ['payment' => $payment, 'initiation' => $initiation] = $this->payments->createForOrder($order, $method);
        } catch (OrderNotPayableException|RenewalUnderWayException $e) {
            // Made with a key, the same request in the same moment may be what paid it — or opened the renewal under way.
            $keyed = $request === null ? null : $this->orders->keyed($user, $request->key);
            if ($keyed === null) {
                return $e instanceof OrderNotPayableException ? CheckoutResult::closed() : throw $e;
            }

            return $this->standing($keyed);
        }

        if ($initiation->transfer !== null) {
            return CheckoutResult::transfer($order, $payment, $initiation->transfer);
        }

        // Settled on the spot (the wallet): the order as it ended — or, the gateway having said no, still open.
        $order->refresh();

        return match (true) {
            $payment->status === PaymentStatus::Failed => CheckoutResult::refused($order, $payment),
            in_array($order->status, [OrderStatus::Fulfilled, OrderStatus::Failed], true) => CheckoutResult::settled($order),
            default => CheckoutResult::processing($order),
        };
    }

    /**
     * A request made again with its key — and the way to pay it named, `$key->method` —, when the order that key came to
     * may not be paid any more — paid, delivered, with support, cancelled —: that order, as it stands. Null when the key
     * came to none yet, or its order is still to be paid: the request goes on as its first did (pay()). For a door that
     * judges a new request before paying it — a plan still sold, a service still renewable, a way to pay still offered —,
     * so a request made again is not refused by what changed since its first.
     *
     * @param \Closure(?OrderKey): Order $open
     * @throws RequestKeyReusedException 422: the key came to an order of something else, or was first sent with another way to pay
     */
    public function replayed(User $user, OrderKey $key, \Closure $open): ?CheckoutResult
    {
        $order = $this->keyedOrder($user, $key, $open);

        return $order === null || self::payable($order) ? null : $this->standing($order, madeAgain: true);
    }

    /**
     * The order the request's key came to — `$open` given the key judges it the same thing asked again, the same way to
     * pay too (OrderService) —; null while it came to none.
     *
     * @param \Closure(?OrderKey): Order $open
     * @throws RequestKeyReusedException
     */
    private function keyedOrder(User $user, OrderKey $key, \Closure $open): ?Order
    {
        return $this->orders->keyed($user, $key->key) === null ? null : $open($key);
    }

    /** Whether the customer may still pay the order: open, and no receipt of theirs with support (Order::payable()). */
    private static function payable(Order $order): bool
    {
        return Order::payable()->whereKey($order->id)->exists();
    }

    /**
     * An order a request made, as it stands now: settled once paid and done with — delivered, failed, refunded —,
     * processing while its delivery runs, and — open with a receipt of its own with support — the transfer that receipt is
     * for. Cancelled: closed, a moment ago elsewhere — or, the request `$madeAgain` with its key, cancelled since, by
     * support or unpaid in its time, maybe days ago.
     */
    private function standing(Order $order, bool $madeAgain = false): CheckoutResult
    {
        $order->refresh();

        return match ($order->status) {
            OrderStatus::Fulfilled, OrderStatus::Failed, OrderStatus::Refunded => CheckoutResult::settled($order),
            OrderStatus::Paid, OrderStatus::Processing => CheckoutResult::processing($order),
            OrderStatus::Cancelled => $madeAgain ? CheckoutResult::cancelled() : CheckoutResult::closed(),
            OrderStatus::Pending => $this->awaiting($order),
        };
    }

    /** An open order's card payment — its receipt with support, else still awaited — with the card it is paid to. */
    private function awaiting(Order $order): CheckoutResult
    {
        $open = $order->payments()->whereIn('status', [PaymentStatus::AwaitingReview->value, PaymentStatus::Pending->value])->get();
        $payment = $open->first(static fn(Payment $payment): bool => $payment->status === PaymentStatus::AwaitingReview) ?? $open->first();
        $transfer = $payment === null ? null : $this->gateways->forPayment($payment)->initiate()->transfer;

        return $payment !== null && $transfer !== null ? CheckoutResult::transfer($order, $payment, $transfer) : CheckoutResult::closed();
    }
}
