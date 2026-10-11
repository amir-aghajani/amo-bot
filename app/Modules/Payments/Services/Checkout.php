<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
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
 * The one checkout of the bot (CheckoutScreen) and the website (CustomerCheckout): orders something — or finds the open
 * order of the same thing — and pays it with the chosen method. A short wallet orders nothing; the wallet settles at
 * once; a card transfer waits for its receipt; a free order is settled by the wallet row whatever was picked.
 *
 * A website request made again with its Idempotency-Key is answered by the order the key came to, as it stands — never
 * a second order or charge, never paid another way. Two such requests at once make one order: the key's unique row and
 * the order's compare-and-swap decide, and the loser describes the winner's result.
 */
final class Checkout
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
        private readonly GatewayRegistry $gateways,
        private readonly PaymentMethods $methods,
    ) {}

    /** What the wallet lacks to pay `$price` (an agent's credit counting); null when it can, and for any other method. */
    public function shortfall(User $user, PaymentMethod $method, string $price): ?Shortfall
    {
        // Nothing is missing to pay nothing (a free order), whatever the wallet holds.
        if (!$method->isWallet() || !Money::isPositive($price)) {
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
     * Pays `$price` with `$method` for what `$open` orders (OrderService::open*()), in this order:
     * - with `$key`, a request made again is answered by its order as it stands once it may no longer be paid, and pays
     *   that order (at its own amount) while it still may;
     * - a short wallet: nothing is ordered;
     * - `$guard`, the caller's own hold (the bot: a checkout's wallet pays once), may close the checkout;
     * - an order paid or closed elsewhere at the same moment is `closed` — or, with a key, the other request's result.
     *
     * @param \Closure(?OrderKey): Order $open
     * @param (\Closure(): bool)|null $guard
     * @throws RequestKeyReusedException 422: the key came to an order of something else, or was first sent with another way to pay
     * @throws TooManyUnpaidOrdersException 422: the website's request would leave its customer too many unpaid orders
     * @throws RenewalUnderWayException a renewal of the service is under way already (none made with this key)
     * @throws DiscountCodeRefusedException 422: the discount code the price was quoted with had its last use taken
     *                                      meanwhile — nothing ordered, nothing charged
     */
    public function pay(User $user, PaymentMethod $method, string $price, \Closure $open, ?string $key = null, ?\Closure $guard = null): CheckoutResult
    {
        $request = $key === null ? null : new OrderKey($key, $method->id);
        $order = $request === null ? null : $this->keyedOrder($user, $request, $open);
        if ($order !== null && !self::payable($order)) {
            return $this->standing($order, madeAgain: true);
        }

        $amount = $order === null ? $price : $order->amount;
        // A free order has nothing to transfer: the wallet row settles it, whichever method was picked.
        if (!Money::isPositive($amount)) {
            $method = $this->methods->wallet();
        }
        $shortfall = $this->shortfall($user, $method, $amount);
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
     * The answer to a request made again with its key once its order can no longer be paid; null when the key came to no
     * order yet or it is still payable. The website asks this before judging a request, so a repeat is not refused by
     * what changed since the first (a plan off sale, a method switched off).
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
     * The order the request's key came to (`$open` checks it is the same request, method included); null for none.
     *
     * @param \Closure(?OrderKey): Order $open
     * @throws RequestKeyReusedException
     */
    private function keyedOrder(User $user, OrderKey $key, \Closure $open): ?Order
    {
        return $this->orders->keyed($user, $key->key) === null ? null : $open($key);
    }

    /** Whether the customer may still pay the order (Order::payable()). */
    private static function payable(Order $order): bool
    {
        return Order::payable()->whereKey($order->id)->exists();
    }

    /**
     * A request's order as it stands now: settled (delivered, failed or refunded), processing, or still open — then its
     * card transfer. A cancelled order is `closed` just now elsewhere, or `cancelled` for a request `$madeAgain` later.
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

    /** An open order's card payment (the one with a receipt in review, else a pending one) and its card. */
    private function awaiting(Order $order): CheckoutResult
    {
        $open = $order->payments()->whereIn('status', [PaymentStatus::AwaitingReview->value, PaymentStatus::Pending->value])->get();
        $payment = $open->first(static fn(Payment $payment): bool => $payment->status === PaymentStatus::AwaitingReview) ?? $open->first();
        $transfer = $payment === null ? null : $this->gateways->forPayment($payment)->initiate()->transfer;

        return $payment !== null && $transfer !== null ? CheckoutResult::transfer($order, $payment, $transfer) : CheckoutResult::closed();
    }
}
