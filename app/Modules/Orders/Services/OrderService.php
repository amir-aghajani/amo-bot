<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Database\Transitions;
use App\Core\Exceptions\DomainRuleException;
use App\Core\Exceptions\ValidationException;
use App\Modules\Agency\Exceptions\TrafficShortException;
use App\Modules\Agency\Services\AgencySettings;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Discounts\Exceptions\DiscountCodeRefusedException;
use App\Modules\Discounts\Services\DiscountCodes;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Enums\OrderPricing;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\FirstPurchaseUsedException;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
use App\Modules\Rewards\Services\OrderRewards;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use App\Modules\Users\Services\WalletSettings;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * The order lifecycle: pending → paid → processing → fulfilled | failed, or cancelled unpaid.
 *
 * An order is made when the customer picks how to pay; picking again finds the same open order (open()). It keeps the
 * price it was made at (a Pricing quote). Every move is a compare-and-swap (Transitions): one payment pays the order, and
 * only the process that claims it for delivery (paid → processing) delivers it, so two approvals or two retries never
 * deliver twice; a delivery whose process died is resumed (resume()). Delivery makes or renews a service, adds extra
 * traffic or time, credits the wallet or adds an agent's traffic; in an agent's shop it first draws the traffic from the
 * agent's pool and gives it back if the delivery fails. Outcomes go to the report group in the same transaction.
 */
final class OrderService
{
    /** `orders.notes` of a cancelled order: support's cancel without a note, and an unpaid order that expired. */
    public const NOTE_CANCELLED_BY_SUPPORT = 'لغو توسط پشتیبانی';
    public const NOTE_EXPIRED = 'پرداخت در مهلت کامل نشد';

    /** A processing claim untouched for this long belongs to a process that died; the admin may retry it. */
    public const STALE_PROCESSING_MINUTES = 10;

    /** A retry that found the order taken: another process is delivering it, or just did. */
    public const DELIVERY_TAKEN = 'تحویل این سفارش همین حالا جای دیگری انجام شد یا در حال انجام است.';

    /** `orders.notes` of a delivery that broke on a bug rather than a panel's or a rule's refusal (the log has the detail). */
    public const DELIVERY_BROKEN = 'تحویل با خطای داخلی برنامه متوقف شد؛ جزئیات در لاگ برنامه است.';

    /** What a delivery reads of an order, for a list of orders delivered one after another to read with them. */
    public const DELIVERY_RELATIONS = ['payments', 'user.ownBot', 'plan', 'server', 'subscription'];

    /** The most unpaid orders a website customer may have open (each may hold a receipt picture on the host). */
    public const UNPAID_MAX = 5;

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly WalletService $wallet,
        private readonly TrafficPool $pool,
        private readonly WalletSettings $walletSettings,
        private readonly AgencySettings $agencySettings,
        private readonly ShopReports $reports,
        private readonly CustomerNotifier $notifier,
        private readonly Pricing $pricing,
        private readonly DiscountCodes $codes,
        private readonly OrderRewards $rewards,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The customer's purchase of the plan on the chosen server, at `$quote` (the checkout's, with its code) or else at
     * Pricing::purchase() without a code — or, made again with its key, the order that key came to.
     *
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     * @throws DiscountCodeRefusedException the code's last use was taken meanwhile
     */
    public function openPurchase(User $user, Plan $plan, Server $server, ?OrderKey $key = null, ?Quote $quote = null): Order
    {
        return $this->open($user, OrderType::Purchase, fn(): Quote => $quote ?? $this->pricing->purchase($user, $plan, $server), ['plan_id' => $plan->id, 'server_id' => $server->id], key: $key);
    }

    /**
     * A free service of the plan as a prize or gift (Rewards\Services\Rewards): always a new order at no price, `pricing`
     * gift (so it is no purchase of the customer's), settled by the wallet row and delivered as any purchase.
     */
    public function openGift(User $user, Plan $plan, Server $server): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'type' => OrderType::Purchase,
            'status' => OrderStatus::Pending,
            'plan_id' => $plan->id,
            'server_id' => $server->id,
            'amount' => '0.00',
            'pricing' => OrderPricing::Gift,
        ]);
    }

    /** Whether a top-up of `$amount` Toman is within the wallet settings' bounds. */
    public function topUpAllowed(int $amount): bool
    {
        return $amount >= $this->walletSettings->topUpMin() && $amount <= WalletSettings::TOPUP_MAX;
    }

    /** The top-up bounds (topUpAllowed()) worded for the customer. */
    public function topUpBounds(): string
    {
        return 'مبلغ شارژ باید بین ' . Money::format($this->walletSettings->topUpMin()) . ' و ' . Money::format(WalletSettings::TOPUP_MAX) . ' باشد.';
    }

    /**
     * A wallet top-up of `$amount` Toman, never discounted — or, made again with its key, the order that key came to
     * (the bounds are only checked for a new order).
     *
     * @throws ValidationException 422 on `amount` outside the bot settings' bounds
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     */
    public function openTopUp(User $user, int $amount, ?OrderKey $key = null): Order
    {
        return $this->open($user, OrderType::WalletTopUp, static fn(): Quote => new Quote(Money::normalize($amount), OrderPricing::Regular), ['amount' => $amount], function () use ($amount): void {
            if (!$this->topUpAllowed($amount)) {
                throw ValidationException::on('amount', $this->topUpBounds());
            }
        }, $key);
    }

    /** Whether an agent may buy `$gb` GB of traffic at once (the agency settings' bounds). */
    public function trafficAllowed(int $gb): bool
    {
        return $gb >= $this->agencySettings->trafficMin() && $gb <= AgencySettings::TRAFFIC_MAX_GB;
    }

    /**
     * `$gb` GB of traffic an agent buys for their bot, at their level's price per GB (never discounted).
     *
     * @throws ValidationException 422 on `gb` outside the agency program's bounds
     */
    public function openTraffic(User $agent, int $gb): Order
    {
        $level = $agent->agencyLevel ?? throw new \LogicException("User #{$agent->id} is not an agent.");
        if (!$this->trafficAllowed($gb)) {
            throw ValidationException::on('gb', 'حجم باید دست‌کم ' . Persian::number($this->agencySettings->trafficMin()) . ' گیگابایت باشد.');
        }

        return $this->open($agent, OrderType::Traffic, static fn(): Quote => new Quote($level->priceOf($gb), OrderPricing::Regular), ['traffic_bytes' => Traffic::bytesOfGb($gb)]);
    }

    /**
     * The customer's renewal of the service, at `$quote` or else Pricing::renewal(). The service's row is locked first,
     * as automatic renewal locks it (AutoRenewal::claim()), and none is opened while another renewal is under way — it
     * would be paid twice. Made again with its key: the key's order, under way or not.
     *
     * @throws RenewalUnderWayException
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     * @throws DiscountCodeRefusedException the code's last use was taken meanwhile
     */
    public function openRenewal(User $user, Subscription $subscription, Plan $plan, ?OrderKey $key = null, ?Quote $quote = null): Order
    {
        return $this->open($user, OrderType::Renewal, fn(): Quote => $quote ?? $this->pricing->renewal($user, $subscription, $plan), ['plan_id' => $plan->id, 'subscription_id' => $subscription->id], function () use ($subscription): void {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->value('id');
            if ($this->renewalUnderWay($subscription)) {
                throw new RenewalUnderWayException($subscription);
            }
        }, $key);
    }

    /**
     * `$quantity` GB or days of an extra for the customer's service, at `$quote` or else `$price` less the automatic
     * discount (Pricing::extra()).
     *
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     * @throws DiscountCodeRefusedException the code's last use was taken meanwhile
     */
    public function openExtra(User $user, Subscription $subscription, ExtraKind $kind, int $quantity, string $price, ?OrderKey $key = null, ?Quote $quote = null): Order
    {
        $shape = ['subscription_id' => $subscription->id] + match ($kind) {
            ExtraKind::Traffic => ['traffic_bytes' => Traffic::bytesOfGb($quantity)],
            ExtraKind::Time => ['days' => $quantity],
        };
        if ($subscription->plan_id !== null) {
            $shape['plan_id'] = $subscription->plan_id;
        }

        return $this->open($user, $kind->orderType(), fn(): Quote => $quote ?? $this->pricing->extra($user, $subscription, $kind, $price), $shape, key: $key);
    }

    /**
     * Whether a renewal of the service is under way: its receipt with support, paid and being delivered, or failed and
     * waiting for support's retry. A renewal still payable (Order::payable()) is not under way.
     */
    public function renewalUnderWay(Subscription $subscription): bool
    {
        return Order::query()
            ->where('subscription_id', $subscription->id)
            ->where('type', OrderType::Renewal->value)
            ->where(static function (Builder $query): void {
                $query->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Failed->value])
                    ->orWhere(static fn(Builder $pending) => $pending
                        ->where('status', OrderStatus::Pending->value)
                        ->whereHas('payments', static fn(Builder $payment) => $payment->where('status', PaymentStatus::AwaitingReview->value)));
            })
            ->exists();
    }

    /** An automatic renewal's order (AutoRenewal::claim()), at Pricing::renewal() without a code. */
    public function createRenewal(User $user, Subscription $subscription, Plan $plan): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'type' => OrderType::Renewal,
            'status' => OrderStatus::Pending,
            'plan_id' => $plan->id,
            'subscription_id' => $subscription->id,
        ] + $this->pricing->renewal($user, $subscription, $plan)->attributes());
    }

    /**
     * Claims the order for a payment that succeeded (pending → paid). False when it is no longer pending; PaymentService
     * then rolls the payment back. Delivery is a separate step (deliver()) after the commit.
     */
    public function markPaid(Order $order): bool
    {
        return Transitions::move($order, 'status', [OrderStatus::Pending], OrderStatus::Paid);
    }

    /**
     * Delivers a paid order, a failed one again (support's retry), or a stale claim. The claim → processing makes two
     * deliveries impossible. True when this call delivered (to fulfilled or failed); false when another process has it.
     *
     * The order is fulfilled in the same transaction that writes what it delivered. A panel's or a rule's refusal fails
     * the order with that reason; any other exception fails it with DELIVERY_BROKEN and is rethrown.
     */
    public function deliver(Order $order): bool
    {
        return $this->claim($order, [OrderStatus::Paid, OrderStatus::Failed]) && $this->carryOut($order);
    }

    /**
     * Delivers an order a dead process left behind (isStale()), as deliver() would — never a failed one, which waits for
     * support. True when this call delivered.
     */
    public function resume(Order $order): bool
    {
        return self::isStale($order) && $this->claim($order, [OrderStatus::Paid]) && $this->carryOut($order);
    }

    /** Cancels an unpaid order with `$reason` as its notes. True when this call cancelled it. */
    public function cancel(Order $order, string $reason): bool
    {
        return Transitions::move($order, 'status', [OrderStatus::Pending], OrderStatus::Cancelled, ['notes' => $reason]);
    }

    /** Paid or processing, and untouched for STALE_PROCESSING_MINUTES: its process died (Order::stale() as a query). */
    public static function isStale(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true)
            && $order->updated_at->lessThanOrEqualTo(now()->subMinutes(self::STALE_PROCESSING_MINUTES));
    }

    /** The order the customer's Idempotency-Key came to; null for none. */
    public function keyed(User $user, string $key): ?Order
    {
        return Order::query()->whereIn('id', RequestKey::query()->select('order_id')->where('user_id', $user->id)->where('key', $key))->first();
    }

    /**
     * Finds the customer's payable order of this shape at the same price and code (touched, so its expiry restarts), or
     * makes one — under a lock on the customer's row, so two taps make one order. A new order with a code takes one of
     * its uses (DiscountCodes::reserve()). `$first` runs first in the same transaction (a lock and a check of its own).
     *
     * With an Idempotency-Key, the key's order comes first whatever became of it, as long as it is the same request (type,
     * shape and method — never the price, which may have moved); otherwise RequestKeyReusedException. A new key is kept
     * for the order the request comes to, in the same transaction; of two requests with one key at once, the key's unique
     * row turns the second away and it gets the first's order. A keyed request makes no new order while the customer has
     * UNPAID_MAX unpaid ones.
     *
     * @param array<string, int> $shape What tells two orders of the type apart, and what a key's order must have too: the
     *                                  plan and its server or its service, a top-up's amount (what it buys), the traffic or
     *                                  the days
     * @param \Closure(): Quote $price What it costs; not asked when the key's order answers the request
     * @param (\Closure(): void)|null $first
     * @param-immediately-invoked-callable $price
     * @param-immediately-invoked-callable $first
     * @throws RequestKeyReusedException
     * @throws TooManyUnpaidOrdersException
     * @throws DiscountCodeRefusedException the quote's code has no use left for a new order
     */
    private function open(User $user, OrderType $type, \Closure $price, array $shape = [], ?\Closure $first = null, ?OrderKey $key = null): Order
    {
        $keyed = $key === null ? null : $this->keyed($user, $key->key);
        if ($keyed !== null) {
            return self::same($user, $keyed, $key, $type, $shape);
        }
        $priced = $price()->attributes();

        try {
            return $this->db->transaction(function () use ($user, $type, $priced, $shape, $first, $key): Order {
                if ($first !== null) {
                    $first();
                }
                User::query()->whereKey($user->id)->lockForUpdate()->value('id');

                $order = Order::payable()
                    ->where(['user_id' => $user->id, 'type' => $type->value, 'amount' => $priced['amount']] + $shape)
                    ->where('discount_code_id', $priced['discount_code_id'])
                    ->latest('id')->lockForUpdate()->first();
                if ($order !== null) {
                    $order->touch();
                } elseif ($key !== null && Order::query()->where('user_id', $user->id)->where('status', OrderStatus::Pending->value)->count() >= self::UNPAID_MAX) {
                    throw new TooManyUnpaidOrdersException();
                } else {
                    if ($priced['discount_code_id'] !== null) {
                        $this->codes->reserve($priced['discount_code_id'], $user);
                    }
                    $order = Order::query()->create(['user_id' => $user->id, 'type' => $type, 'status' => OrderStatus::Pending] + $priced + $shape);
                }
                if ($key !== null) {
                    RequestKey::query()->create(['user_id' => $user->id, 'key' => $key->key, 'order_id' => $order->id, 'payment_method_id' => $key->method]);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same request made at the same moment kept its key first: its order is the answer.
            $keyed = $key === null ? null : $this->keyed($user, $key->key);
            if ($keyed === null || $key === null) {
                throw $e;
            }

            return self::same($user, $keyed, $key, $type, $shape);
        }
    }

    /**
     * The key's order, when the request asks the same thing again: the same type and shape (never the price) and the
     * same payment method as the key was first sent with.
     *
     * @param array<string, int> $shape
     * @throws RequestKeyReusedException
     */
    private static function same(User $user, Order $order, OrderKey $key, OrderType $type, array $shape): Order
    {
        $same = $order->type === $type;
        foreach ($shape as $column => $value) {
            $kept = $order->getAttribute($column);
            $same = $same && is_numeric($kept) && (int) $kept === $value;
        }
        if (!$same) {
            throw new RequestKeyReusedException();
        }

        $method = RequestKey::query()->where('user_id', $user->id)->where('key', $key->key)->value('payment_method_id');

        return $method === null || (int) $method === $key->method ? $order : throw RequestKeyReusedException::otherMethod();
    }

    /**
     * Claims the order for delivery (→ processing) from one of `$from`, or takes over a stale claim. True when this call
     * took it.
     *
     * @param list<OrderStatus> $from
     */
    private function claim(Order $order, array $from): bool
    {
        $claimed = Transitions::move($order, 'status', $from, OrderStatus::Processing, ['notes' => null, 'diagnosis' => null])
            || Transitions::reclaim($order, 'status', OrderStatus::Processing, self::STALE_PROCESSING_MINUTES * 60, ['notes' => null, 'diagnosis' => null]);
        if (!$claimed) {
            $this->logger->info('Order {id} was not claimed for delivery: it is {status}', ['id' => $order->id, 'status' => $order->status->value]);
        }

        return $claimed;
    }

    /** Delivers an order this process claimed; true once it is fulfilled or failed with its reason. */
    private function carryOut(Order $order): bool
    {
        try {
            match ($order->type) {
                // The panel is talked to outside any transaction: a slow panel must not hold locks.
                OrderType::Purchase => $this->deliverPurchase($order),
                OrderType::Renewal => $this->withTraffic($order, fn() => $this->provisioning->renew($order, fn() => $this->fulfilled($order))),
                OrderType::ExtraTraffic => $this->withTraffic($order, fn() => $this->provisioning->addExtra($order, fn() => $this->fulfilled($order))),
                // Days cost an agent's pool nothing (as support's extension of a service does not).
                OrderType::ExtraTime => $this->provisioning->addExtra($order, fn() => $this->fulfilled($order)),
                OrderType::WalletTopUp => $this->db->transaction(function () use ($order): void {
                    $this->wallet->credit($order->user, $order->amount, WalletService::describeTopUp($order));
                    $this->fulfilled($order);
                }),
                OrderType::Traffic => $this->db->transaction(function () use ($order): void {
                    $bot = $order->user->ownBot ?? throw new \LogicException("The agent of traffic order #{$order->id} has no bot.");
                    $this->pool->add($bot, (int) $order->traffic_bytes, $order);
                    $this->fulfilled($order);
                }),
            };
        } catch (ProviderException $e) {
            $this->logger->warning('Delivery of order {id} failed: {message}', ['id' => $order->id, 'message' => $e->getMessage()]);
            // A safe summary for any reader of the order, and the owner's diagnosis (the panel's address) kept apart.
            $this->failed($order, ProviderErrorPresenter::summary($e), $e, ProviderErrorPresenter::describe($e));

            return true;
        } catch (DomainRuleException $e) {
            $this->logger->warning('Delivery of order {id} failed: {message}', ['id' => $order->id, 'message' => $e->getMessage()]);
            $this->failed($order, $e->getMessage(), $e);

            return true;
        } catch (\Throwable $e) {
            $this->failed($order, self::DELIVERY_BROKEN, $e);

            throw $e;
        }
        // Cashback and loyalty after the delivery's commit; they never affect it.
        if ($order->status === OrderStatus::Fulfilled) {
            $this->rewards->delivered($order);
        }

        return true;
    }

    /**
     * Delivers a purchase. One made at the first-purchase price fails when another purchase of the customer's was paid
     * before it (two ordered at that price before either was paid: only the first paid is the first purchase); a retry
     * of a failed first purchase still counts as first, whatever was bought since.
     *
     * @throws FirstPurchaseUsedException
     */
    private function deliverPurchase(Order $order): void
    {
        if ($order->pricing === OrderPricing::FirstPurchase && self::boughtBefore($order)) {
            throw new FirstPurchaseUsedException();
        }

        $this->withTraffic($order, fn() => $this->provisioning->provision($order, fn() => $this->fulfilled($order)));
    }

    /**
     * Whether another purchase of the customer's was paid before this one (by `paid_at`, then id): any purchase
     * (Order::bought(), the first-purchase price's rule), or with `$withPrice` one with a price (Order::paidPurchases(),
     * which purchase is a referral's first — ReferralGifts).
     */
    public static function boughtBefore(Order $order, bool $withPrice = false): bool
    {
        $paidAt = Payment::query()->where('order_id', $order->id)->whereNotNull('paid_at')->min('paid_at');
        $before = Payment::query()->select('order_id')->whereNotNull('paid_at')->where(static fn(Builder $payment) => $payment
            ->where('paid_at', '<', $paidAt)
            ->orWhere(static fn(Builder $tie) => $tie->where('paid_at', $paidAt)->where('order_id', '<', $order->id)));
        $purchases = $withPrice ? Order::paidPurchases() : Order::bought();

        return $purchases->where('user_id', $order->user_id)->whereKeyNot($order->id)->whereIn('id', $before)->exists();
    }

    /** Inside the transaction that wrote what was delivered: marks the order fulfilled and reports it. */
    private function fulfilled(Order $order): void
    {
        if (!Transitions::move($order, 'status', [OrderStatus::Processing], OrderStatus::Fulfilled, ['fulfilled_at' => now()])) {
            throw new \LogicException("Order #{$order->id} left processing while it was being delivered.");
        }
        $this->reports->orderDelivered($order);
    }

    /**
     * Fails the claimed delivery with a reason anyone may read and the owner's `$diagnosis`, reported in the same
     * transaction; an agent short of traffic is notified.
     */
    private function failed(Order $order, string $reason, \Throwable $cause, ?string $diagnosis = null): void
    {
        $failed = $this->db->transaction(function () use ($order, $reason, $diagnosis): bool {
            if (!Transitions::move($order, 'status', [OrderStatus::Processing], OrderStatus::Failed, ['notes' => $reason, 'diagnosis' => $diagnosis])) {
                return false;
            }
            $this->reports->orderFailed($order);

            return true;
        });
        if (!$failed) {
            return;
        }
        if ($cause instanceof TrafficShortException) {
            $this->notifier->agencyTrafficShort($order);
        }
    }

    /**
     * In an agent's shop, draws the order's traffic from the agent's pool before delivering and gives it back if the
     * delivery fails (TrafficPool::draw() never draws twice for one order).
     *
     * @param \Closure(): mixed $deliver
     */
    private function withTraffic(Order $order, \Closure $deliver): void
    {
        $bot = $order->shop();
        if ($bot->isMain()) {
            $deliver();

            return;
        }

        $this->pool->draw($order, $bot);
        try {
            $deliver();
        } catch (\Throwable $e) {
            $this->pool->giveBack($order, $bot);
            throw $e;
        }
    }
}
