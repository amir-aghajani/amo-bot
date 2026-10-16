<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\DTO\CheckoutResult;
use App\Modules\Payments\DTO\Shortfall;
use App\Modules\Payments\Enums\OnlineSettlement;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\MethodUnavailableException;
use App\Modules\Payments\Exceptions\PaymentHeldException;
use App\Modules\Payments\Exceptions\ReceiptInReviewException;
use App\Modules\Payments\GatewayRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Users\Models\User;
use App\Support\Money;

/**
 * The one checkout of the bot (CheckoutScreen) and the website (CustomerCheckout): orders something — or finds the open
 * order of the same thing — and pays it with the chosen method. A short wallet orders nothing; the wallet settles at
 * once; a card transfer waits for its receipt; an online gateway's page waits for the gateway's word; a free order is
 * settled by the wallet row whatever was picked.
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
        private readonly ReceiptsInReview $inReview,
        private readonly OnlinePayments $online,
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
     * Pays `$price` with `$method` for the `$type` order `$open` makes (OrderService::open*()), in this order:
     * - with `$key`, a request made again is answered by its order as it stands once it may no longer be paid, and pays
     *   that order (at its own amount) while it still may;
     * - a method that does not take the amount now — its bounds, or no card of it with room today (PaymentMethods::
     *   allow(), with the customer's own open payment shown again) —: refused, nothing ordered; and should the payment
     *   find no card after all, the order this request made is cancelled at once and its key forgotten (withdraw());
     * - a card payment while another receipt of the customer's is in review (ReceiptsInReview): refused, nothing ordered;
     * - a short wallet: nothing is ordered;
     * - `$guard`, the caller's own hold (the bot: a checkout's wallet pays once), may close the checkout;
     * - an order paid or closed elsewhere at the same moment is `closed` — or, with a key, the other request's result;
     * - an order with an online payment held for support (OnlinePayments::isHeld()) is refused, whatever the method;
     * - an online method's page of the order that is closing is asked about first (OnlinePayments::paidBefore()): paid
     *   there, the order as it stands is the answer — `closed` when another call settled it a moment earlier, or its
     *   order closed in that moment —, and no new page is opened.
     *
     * @param \Closure(?OrderKey): Order $open
     * @param (\Closure(): bool)|null $guard
     * @throws RequestKeyReusedException 422: the key came to an order of something else, or was first sent with another way to pay
     * @throws TooManyUnpaidOrdersException 422: the website's request would leave its customer too many unpaid orders
     * @throws RenewalUnderWayException a renewal of the service is under way already (none made with this key)
     * @throws SellingSuspendedException 422: an agent's shop whose selling the main shop suspended orders nothing
     * @throws DiscountCodeRefusedException 422: the discount code the price was quoted with had its last use taken
     *                                      meanwhile — nothing ordered, nothing charged
     * @throws ReceiptInReviewException 422: one receipt at a time, and the customer's last is still in review
     * @throws MethodUnavailableException 422 on `method_id`: the amount is outside the method's bounds, or no card of it
     *                                    has room for it today — nothing left ordered, nothing paid
     * @throws PaymentHeldException 422 on `status`: the order's online payment is held for support — nothing paid
     */
    public function pay(User $user, PaymentMethod $method, OrderType $type, string $price, \Closure $open, ?string $key = null, ?\Closure $guard = null): CheckoutResult
    {
        $request = $key === null ? null : new OrderKey($key, $method->id);
        $order = $request === null ? null : $this->keyedOrder($user, $request, $open);
        if ($order !== null && !self::payable($order)) {
            return $this->standing($order, madeAgain: true);
        }

        if ($order !== null) {
            self::refuseHeld($order);
        }

        $amount = $order === null ? $price : $order->amount;
        // A free order has nothing to transfer: the wallet row settles it, whichever method was picked.
        if (!Money::isPositive($amount)) {
            $method = $this->methods->wallet();
        }
        $this->methods->allow($method, $type, $amount, $user);
        // One receipt at a time: no card payment waiting for a receipt while another of theirs is in review.
        $this->inReview->allowCard($user, $method, $amount, $order);
        $shortfall = $this->shortfall($user, $method, $amount);
        if ($shortfall !== null) {
            return CheckoutResult::short($shortfall);
        }
        if ($guard !== null && !$guard()) {
            return CheckoutResult::closed();
        }

        $opening = $order === null;
        try {
            $order ??= $open($request);
            // An order made just now has no payment to be held; one the caller found again may.
            if ($opening && !$order->wasRecentlyCreated) {
                self::refuseHeld($order);
            }
            // A page of it about to close may have been paid already: asked before another is opened, so nobody pays twice.
            $paid = $this->online->paidBefore($order, $method);
            if ($paid !== null) {
                // Settled by this ask, the order as it stands is the customer's word on it. Settled by another call a moment
                // earlier — which told them —, or its order closed in that moment, there is nothing more to show — but to a
                // request made again with its key, which describes its order as it stands.
                return $paid === OnlineSettlement::Settled || $request !== null ? $this->standing($order) : CheckoutResult::closed();
            }
            ['payment' => $payment, 'initiation' => $initiation] = $this->payments->createForOrder($order, $method);
        } catch (MethodUnavailableException $e) {
            // No card took it after all — the last room went in the same moment, or the open payment that let the method
            // in was another order's: what this request opened goes with the refusal.
            if ($opening && $order !== null) {
                $this->withdraw($order, $request, $e->getMessage());
            }

            throw $e;
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
        if ($initiation->link !== null) {
            return CheckoutResult::link($order, $payment, $initiation->link);
        }

        // Settled on the spot (the wallet): the order as it ended — or, the gateway having said no (the wallet spent, an
        // online gateway that would not open it), still open.
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

    /**
     * Takes back what a request opened for a payment its method then refused: the order, when the request made it,
     * cancelled with the refusal as its notes (a code's use given back), and the key it kept forgotten — made again, the
     * request is a new one, with this way to pay or another.
     */
    private function withdraw(Order $order, ?OrderKey $request, string $refusal): void
    {
        if ($order->wasRecentlyCreated) {
            $this->orders->cancel($order, $refusal);
        }
        if ($request !== null) {
            RequestKey::query()->where('user_id', $order->user_id)->where('key', $request->key)->where('order_id', $order->id)->delete();
        }
    }

    /** Whether the customer may still pay the order (Order::payable()). */
    private static function payable(Order $order): bool
    {
        return Order::payable()->whereKey($order->id)->exists();
    }

    /**
     * An online payment of the order held for support may be money the gateway took: the order is paid no other way
     * meanwhile.
     *
     * @throws PaymentHeldException
     */
    private static function refuseHeld(Order $order): void
    {
        // Still waiting — pending, or failed with its page —: one support cancelled holds nothing (OnlinePayments::awaitsSupport()).
        $held = $order->payments()->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Failed->value])->whereNotNull('invoice->held')->first();
        if ($held !== null) {
            throw new PaymentHeldException($held);
        }
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

    /**
     * An open order's card payment (the one with a receipt in review, else a pending one) and the card it was shown — the
     * same card every time —, or support's private chat for one shown none; or its latest online payment's page — never
     * one held for support.
     */
    private function awaiting(Order $order): CheckoutResult
    {
        $open = $order->payments()->whereIn('status', [PaymentStatus::AwaitingReview->value, PaymentStatus::Pending->value])->latest('id')->get()->reject(OnlinePayments::isHeld(...));
        $payment = $open->first(static fn(Payment $payment): bool => $payment->status === PaymentStatus::AwaitingReview) ?? $open->first();
        $initiation = $payment === null ? null : $this->gateways->forPayment($payment)->initiate($payment);

        return match (true) {
            $initiation?->transfer !== null => CheckoutResult::transfer($order, $payment, $initiation->transfer),
            $initiation?->link !== null => CheckoutResult::link($order, $payment, $initiation->link),
            default => CheckoutResult::closed(),
        };
    }
}
