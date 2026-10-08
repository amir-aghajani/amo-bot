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
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\DTO\OrderKey;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\RenewalUnderWayException;
use App\Modules\Orders\Exceptions\RequestKeyReusedException;
use App\Modules\Orders\Exceptions\TooManyUnpaidOrdersException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\RequestKey;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Providers\Models\Server;
use App\Modules\Providers\Services\ProviderErrorPresenter;
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
 * Order lifecycle: open -> (payment) -> paid -> processing -> fulfilled | failed — or cancelled while nobody paid it.
 * A customer's order is made when they pick how to pay, and a second pick of the same thing finds the open one
 * (open*()); a request the website makes again with its key (its Idempotency-Key) gets the order that key came to, and
 * the website leaves a customer UNPAID_MAX unpaid orders at most. Delivery provisions/renews a subscription, credits the wallet or adds an agent's traffic depending on the
 * order type; a purchase or a renewal in an agent's bot first takes the plan's traffic out of the agent's pool
 * (TrafficPool, once per order) and gets it back when the delivery failed — a pool that cannot cover it fails the
 * order, and the agent is told in the main bot.
 * Every step is a compare-and-swap (Transitions): one payment pays the order (the claim happens inside
 * the payment's own transaction), and the process that claims it for delivery is the only one
 * delivering it — an admin's approve racing the auto-approve timer, or a retry racing another retry,
 * never provisions twice; a delivery whose process died is resumed (resume(), ResumeDeliveriesTask).
 * What a delivery came to — the sale, the renewal, the charged wallet, or the failure that needs the
 * admin's retry — is reported to the admins' report group (ShopReports).
 */
final class OrderService
{
    /** `orders.notes` of a cancelled order: support's cancel without a note, nobody finishing the payment in time. */
    public const NOTE_CANCELLED_BY_SUPPORT = 'لغو توسط پشتیبانی';
    public const NOTE_EXPIRED = 'پرداخت در مهلت کامل نشد';

    /** A processing claim untouched for this long belongs to a process that died; the admin may retry it. */
    public const STALE_PROCESSING_MINUTES = 10;

    /** A retry that found the order taken: another process is delivering it, or just did. */
    public const DELIVERY_TAKEN = 'تحویل این سفارش همین حالا جای دیگری انجام شد یا در حال انجام است.';

    /** `orders.notes` of a delivery that broke on no panel's and no rule's refusal — a fault of the shop's own, which its log has in full. */
    public const DELIVERY_BROKEN = 'تحویل با خطای داخلی برنامه متوقف شد؛ جزئیات در لاگ برنامه است.';

    /** What a delivery reads of an order, for a list of orders delivered one after another to read with them. */
    public const DELIVERY_RELATIONS = ['payments', 'user.ownBot', 'plan', 'server', 'subscription'];

    /**
     * The unpaid orders a customer may have open when the website's checkout makes another (a request with its key): each
     * one is a payment that takes a receipt — a picture kept on the host —, and they go only once paid or expired.
     */
    public const UNPAID_MAX = 5;

    public function __construct(
        private readonly ProvisioningService $provisioning,
        private readonly WalletService $wallet,
        private readonly TrafficPool $pool,
        private readonly WalletSettings $walletSettings,
        private readonly AgencySettings $agencySettings,
        private readonly ShopReports $reports,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The customer's purchase of the plan on the server they picked among its servers, at the plan's price — or, made
     * again with its key, the order that key came to (open()).
     *
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     */
    public function openPurchase(User $user, Plan $plan, Server $server, ?OrderKey $key = null): Order
    {
        return $this->open($user, OrderType::Purchase, Money::normalize($plan->price), ['plan_id' => $plan->id, 'server_id' => $server->id], key: $key);
    }

    /** Whether the wallet may be charged by `$amount` Toman: within the bot settings' bounds. */
    public function topUpAllowed(int $amount): bool
    {
        return $amount >= $this->walletSettings->topUpMin() && $amount <= WalletSettings::TOPUP_MAX;
    }

    /** What a top-up may be (topUpAllowed()), in the customer's words: the bot settings' least and the most the shop takes. */
    public function topUpBounds(): string
    {
        return 'مبلغ شارژ باید بین ' . Money::format($this->walletSettings->topUpMin()) . ' و ' . Money::format(WalletSettings::TOPUP_MAX) . ' باشد.';
    }

    /**
     * A wallet top-up of `$amount` Toman — or, made again with its key, the order that key came to: the bounds are a new
     * order's, which they may have moved past since. Its amount is what it buys, so a key's order has it too.
     *
     * @throws ValidationException 422 on `amount` outside the bot settings' bounds
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     */
    public function openTopUp(User $user, int $amount, ?OrderKey $key = null): Order
    {
        return $this->open($user, OrderType::WalletTopUp, Money::normalize($amount), ['amount' => $amount], function () use ($amount): void {
            if (!$this->topUpAllowed($amount)) {
                throw ValidationException::on('amount', $this->topUpBounds());
            }
        }, $key);
    }

    /** Whether an agent may buy `$gb` GB of traffic at once: within the agency program's bounds. */
    public function trafficAllowed(int $gb): bool
    {
        return $gb >= $this->agencySettings->trafficMin() && $gb <= AgencySettings::TRAFFIC_MAX_GB;
    }

    /**
     * `$gb` GB of traffic an agent buys for their bot (in the main bot), at their level's price per GB.
     *
     * @throws ValidationException 422 on `gb` outside the agency program's bounds
     */
    public function openTraffic(User $agent, int $gb): Order
    {
        $level = $agent->agencyLevel ?? throw new \LogicException("User #{$agent->id} is not an agent.");
        if (!$this->trafficAllowed($gb)) {
            throw ValidationException::on('gb', 'حجم باید دست‌کم ' . Persian::number($this->agencySettings->trafficMin()) . ' گیگابایت باشد.');
        }

        return $this->open($agent, OrderType::Traffic, $level->priceOf($gb), ['traffic_bytes' => Traffic::bytesOfGb($gb)]);
    }

    /**
     * The customer's renewal of the service on the plan, at its price — «♻️ تمدید سرویس» in the bot, the service's renewal
     * on the website. The service's row is locked first, as an automatic renewal locks it (AutoRenewal::claim()), so the
     * two never both open one; and none is opened while another renewal of it is under way (renewalUnderWay()): the
     * service would be renewed — and paid for — twice. Made again with its key, the order that key came to, under way or not.
     *
     * @throws RenewalUnderWayException
     * @throws RequestKeyReusedException the key came to an order of something else
     * @throws TooManyUnpaidOrdersException
     */
    public function openRenewal(User $user, Subscription $subscription, Plan $plan, ?OrderKey $key = null): Order
    {
        return $this->open($user, OrderType::Renewal, Money::normalize($plan->price), ['plan_id' => $plan->id, 'subscription_id' => $subscription->id], function () use ($subscription): void {
            Subscription::query()->whereKey($subscription->id)->lockForUpdate()->value('id');
            if ($this->renewalUnderWay($subscription)) {
                throw new RenewalUnderWayException($subscription);
            }
        }, $key);
    }

    /**
     * Whether a renewal of the service is under way: its receipt with support, or paid and being delivered — or waiting
     * for support's retry, the delivery having failed. Only a renewal still to be paid (Order::payable()) is not: the
     * customer may pay it, or start another.
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

    /** A renewal of the service at its plan's price — «تمدید خودکار»'s, made once per due service (AutoRenewal::claim()). */
    public function createRenewal(User $user, Subscription $subscription, Plan $plan): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'type' => OrderType::Renewal,
            'status' => OrderStatus::Pending,
            'plan_id' => $plan->id,
            'subscription_id' => $subscription->id,
            'amount' => Money::normalize($plan->price),
        ]);
    }

    /**
     * A payment for this order succeeded: claim pending → paid. False when the order is not pending
     * any more (paid by another payment, cancelled meanwhile) — PaymentService then unwinds the
     * payment with it. The delivery is a separate step (deliver()), after the caller's commit.
     */
    public function markPaid(Order $order): bool
    {
        return Transitions::move($order, 'status', [OrderStatus::Pending], OrderStatus::Paid);
    }

    /**
     * Deliver what was bought: a paid order, a failed one again (a retry after a panel outage), or a stale processing one
     * (isStale(): its process died mid-delivery). The claim → processing is what makes two deliveries impossible. True
     * when this call did the delivery — took it to fulfilled or failed; false when another process has it or had it
     * (delivering it this moment, or done with it): what came of it is that one's to tell.
     *
     * The order is fulfilled in the same transaction that writes what it delivered — the service's row, the wallet's or
     * the traffic's line. A delivery the shop's rules or a panel refused — no server, the agent's traffic short, the
     * panel's error — fails the order with that reason (for the admin, the report group and the retry); anything else is
     * a fault: the order fails with the general reason, and the fault goes on up.
     */
    public function deliver(Order $order): bool
    {
        return $this->claim($order, [OrderStatus::Paid, OrderStatus::Failed]) && $this->carryOut($order);
    }

    /**
     * Deliver what a process that died left behind: an order paid with nobody delivering it (isStale()) — its delivery
     * never started, or its claim went quiet —, as deliver() would. Never a failed one: a failure waits for support. True
     * when this call did the delivery; false when the order is not stale, or another process has it or had it.
     */
    public function resume(Order $order): bool
    {
        return self::isStale($order) && $this->claim($order, [OrderStatus::Paid]) && $this->carryOut($order);
    }

    /** The customer or support drops an order nobody paid. True when this call cancelled it; anything else stays as it is. */
    public function cancel(Order $order, string $reason): bool
    {
        return Transitions::move($order, 'status', [OrderStatus::Pending], OrderStatus::Cancelled, ['notes' => $reason]);
    }

    /**
     * Paid, and nobody is delivering it: a processing claim — or a payment whose delivery never started
     * (its process died between the two) — untouched for STALE_PROCESSING_MINUTES.
     */
    public static function isStale(Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing], true)
            && $order->updated_at->lessThanOrEqualTo(now()->subMinutes(self::STALE_PROCESSING_MINUTES));
    }

    /**
     * The order the customer's request with this key (the website's Idempotency-Key) came to — made, or found open
     * (`request_keys`); null while it came to none.
     */
    public function keyed(User $user, string $key): ?Order
    {
        return Order::query()->whereIn('id', RequestKey::query()->select('order_id')->where('user_id', $user->id)->where('key', $key))->first();
    }

    /**
     * The customer's open order of this shape — one they started and may still pay (Order::payable()), at today's price;
     * picked again, its expiry counts from now (ExpireOrdersTask) — or a new one, under a lock on their row: two taps at
     * once make one order. `$first` runs first in the same transaction: a lock of the order's own, and its refusal.
     *
     * With a request's key (the website's Idempotency-Key — `request_keys`, one a customer — and the way to pay it asks
     * for) the order that key came to comes first, whatever became of it — paid, delivered, cancelled: the caller answers
     * it as it stands — while it is the same thing asked again (its type and shape — never its price, which may have moved
     * since — and the way to pay the key was first sent with; else RequestKeyReusedException): never a second order, nor
     * the order paid another way. Otherwise the key is kept for the order the request comes to — the one it makes, or the
     * open one it finds, whatever key that one came by —, with its way to pay, in the transaction that makes or finds it,
     * so a request made again finds it however it was paid. Of two requests with one key in the same moment, the key's
     * row (unique a customer) turns the second's away, and that one gets the first's order. Such a request — the
     * website's — makes no new order while its customer has UNPAID_MAX unpaid ones open.
     *
     * @param array<string, int> $shape What tells two orders of the type apart, and what a key's order must have too: the
     *                                  plan and its server or its service, a top-up's amount (what it buys), the traffic
     * @param (\Closure(): void)|null $first
     * @throws RequestKeyReusedException
     * @throws TooManyUnpaidOrdersException
     */
    private function open(User $user, OrderType $type, string $amount, array $shape = [], ?\Closure $first = null, ?OrderKey $key = null): Order
    {
        $keyed = $key === null ? null : $this->keyed($user, $key->key);
        if ($keyed !== null) {
            return self::same($user, $keyed, $key, $type, $shape);
        }

        try {
            return $this->db->transaction(function () use ($user, $type, $amount, $shape, $first, $key): Order {
                if ($first !== null) {
                    $first();
                }
                User::query()->whereKey($user->id)->lockForUpdate()->value('id');

                $order = Order::payable()->where(['user_id' => $user->id, 'type' => $type->value, 'amount' => $amount] + $shape)->latest('id')->lockForUpdate()->first();
                if ($order !== null) {
                    $order->touch();
                } elseif ($key !== null && Order::query()->where('user_id', $user->id)->where('status', OrderStatus::Pending->value)->count() >= self::UNPAID_MAX) {
                    throw new TooManyUnpaidOrdersException();
                } else {
                    $order = Order::query()->create(['user_id' => $user->id, 'type' => $type, 'status' => OrderStatus::Pending, 'amount' => $amount] + $shape);
                }
                if ($key !== null) {
                    RequestKey::query()->create(['user_id' => $user->id, 'key' => $key->key, 'order_id' => $order->id, 'payment_method_id' => $key->method]);
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same request, made in the same moment, came to its order with this key first: that one is the answer.
            $keyed = $key === null ? null : $this->keyed($user, $key->key);
            if ($keyed === null || $key === null) {
                throw $e;
            }

            return self::same($user, $keyed, $key, $type, $shape);
        }
    }

    /**
     * The order a request's key came to, when the request asks the same thing again: its type and what tells it apart
     * (the plan and the server or the service, a top-up's amount) — never its price, which may have moved since: a
     * request made again is answered by its own order, at the price it was made at —, and the way to pay the key was
     * first sent with: another would pay the order another way, which no request made again asks. (A key kept before
     * keys kept their way to pay — none, a week's at most — is answered whatever way is sent, as it was then.)
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
     * The claim → processing that makes two deliveries impossible: from one of `$from`, or a processing claim gone quiet
     * (its process died). True when this call took it.
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

    /** The delivery of an order this process claimed: true once it is fulfilled, or failed with its reason. */
    private function carryOut(Order $order): bool
    {
        try {
            match ($order->type) {
                // The panel is talked to outside any transaction: a slow panel must not hold locks.
                OrderType::Purchase => $this->withTraffic($order, fn() => $this->provisioning->provision($order, fn() => $this->fulfilled($order))),
                OrderType::Renewal => $this->withTraffic($order, fn() => $this->provisioning->renew($order, fn() => $this->fulfilled($order))),
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
            // A connector's failure in a word for whoever of the shop reads the order (an agent, its admins on its website),
            // and the owner's diagnosis apart — never a panel's address where anyone else reads it.
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

        return true;
    }

    /**
     * Inside the transaction that wrote what the order delivered: its completion — and the report group's word on it —,
     * which land with it or not at all.
     */
    private function fulfilled(Order $order): void
    {
        if (!Transitions::move($order, 'status', [OrderStatus::Processing], OrderStatus::Fulfilled, ['fulfilled_at' => now()])) {
            throw new \LogicException("Order #{$order->id} left processing while it was being delivered.");
        }
        $this->reports->orderDelivered($order);
    }

    /**
     * The claimed delivery came to nothing: failed with the reason anyone of the shop may read, and the owner's diagnosis
     * of a panel that failed it (`$diagnosis`) — told to the group in the same transaction, and to an agent short of
     * traffic.
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
     * A delivery of an agent's bot takes the plan's traffic out of the agent's pool first, and puts it back when the
     * delivery failed — a retry draws again; one that finds it drawn already (its process died) does not.
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
