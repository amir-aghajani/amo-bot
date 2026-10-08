<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Transitions;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Users\Enums\UserStatus;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * «تمدید خودکار»: a service whose customer turned the switch on is renewed from their wallet once its deadline is within
 * the admin's number of days (RenewalSettings) — a renewal order at the plan's price, paid by the wallet (an agent's
 * credit too), delivered like any other (ProvisioningService::renew()) — and the customer is told what came of it:
 * renewed, the wallet short (once per renewal window), or paid but not delivered (support finishes it). Only a running
 * service with a deadline qualifies: one still waiting for its first connection has none yet, one that never ends needs
 * none, and an ended one is renewed by hand. Two runs never charge a service twice (claim()).
 */
final class AutoRenewal
{
    /** `orders.notes` of a renewal the wallet could not pay after all (its balance dropped meanwhile). */
    private const NOTE_UNPAID = 'تمدید خودکار: موجودی کیف پول کافی نبود';

    public function __construct(
        private readonly RenewalSettings $settings,
        private readonly ProvisioningService $provisioning,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly PaymentMethods $methods,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the customer is offered the switch: the service could renew itself, and the wallet — which pays — is on. */
    public function offeredFor(Subscription $subscription): bool
    {
        return self::renewable($subscription) && $this->walletOn();
    }

    /**
     * offeredFor() of several services at once — a page of a customer's on their website —, by id: the wallet asked
     * once for them all.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, bool>
     */
    public function offeredForAll(iterable $subscriptions): array
    {
        $walletOn = $this->walletOn();
        $offered = [];
        foreach ($subscriptions as $subscription) {
            $offered[$subscription->id] = $walletOn && self::renewable($subscription);
        }

        return $offered;
    }

    /** Whether the wallet, which pays every automatic renewal, is on — without it nothing is renewed. */
    public function walletOn(): bool
    {
        return $this->methods->enabledWallet() !== null;
    }

    /** The service runs, ends some day, and still has its plan (the price). */
    public static function renewable(Subscription $subscription): bool
    {
        return $subscription->isActive() && $subscription->duration_days > 0 && $subscription->plan_id !== null;
    }

    /**
     * The customer's switch — the bot's button, their website's —, set only while it is offered (offeredFor()).
     *
     * @throws AutoRenewUnavailableException 422 on `auto_renew`
     */
    public function set(Subscription $subscription, bool $on): void
    {
        if (!$this->offeredFor($subscription)) {
            throw new AutoRenewUnavailableException();
        }
        $subscription->forceFill(['auto_renew' => $on])->save();
    }

    /**
     * The services to renew now: the switch on, the deadline within the admin's days, the customer not banned — the
     * nearest deadline first.
     *
     * @return list<Subscription>
     */
    public function due(): array
    {
        return Subscription::expiringWithin($this->settings->autoRenewDays())
            ->where('auto_renew', true)
            ->whereHas('user', static fn(Builder $user) => $user->where('status', UserStatus::Active->value))
            ->with(['user', 'plan', 'server'])
            ->oldest('expires_at')
            ->get()->values()->all();
    }

    /**
     * Renew one service, if it is still due, and tell the customer what came of it. Its panel is asked first: the
     * panel's deadline is the truth (an admin may have extended the client there), and a panel out of reach — or one the
     * shop is leaving alone a while — waits for the next run with nothing charged. A wallet that cannot pay is told once
     * per renewal window; one that can pays a renewal order, which is then delivered like any other — and one whose
     * delivery broke on a fault of the shop's own (paid, the order failed for support's retry) is told so too, the fault
     * going on up for the run to log.
     */
    public function renew(Subscription $subscription): void
    {
        $wallet = $this->methods->enabledWallet();
        $plan = $subscription->plan;
        if ($wallet === null || $plan === null || $subscription->server->isBackingOff()) {
            return;
        }

        try {
            $this->provisioning->inspect($subscription);
        } catch (ProviderException $e) {
            $this->logger->warning('Auto-renewal of subscription {id} waits for {server}: {message}', ['id' => $subscription->id, 'server' => $subscription->server->name, 'message' => $e->getMessage()]);

            return;
        }
        $deadline = $this->dueDeadline($subscription);
        if ($deadline === null) {
            return;
        }

        $price = Money::normalize($plan->price);
        if (Money::compare($subscription->user->refresh()->spendable(), $price) < 0) {
            $this->short($subscription, $deadline, $price);

            return;
        }

        $order = $this->claim($subscription, $plan);
        if ($order === null) {
            return;
        }

        try {
            ['payment' => $payment] = $this->payments->createForOrder($order, $wallet);
        } catch (OrderNotPayableException) {
            // Closed in the same moment (support cancelled it): nothing was charged.
            return;
        } catch (\Throwable $e) {
            // Paid, and the delivery broke on a fault of the shop's own: the order failed for support's retry, and the
            // customer hears it as of any renewal paid but not delivered. The fault goes on, to the run's log.
            if ($order->refresh()->status === OrderStatus::Failed) {
                $this->notifier->renewalFailed($order);
            }

            throw $e;
        }
        if (!$payment->isPaid()) {
            // The balance dropped below the price since it was read (another payment in the same moment).
            $this->orders->cancel($order, self::NOTE_UNPAID);
            $this->short($subscription, $deadline, $price);

            return;
        }

        $order->refresh();
        $this->logger->info('Auto-renewal of subscription {id}: order {order} is {status}', ['id' => $subscription->id, 'order' => $order->id, 'status' => $order->status->value]);
        $order->status === OrderStatus::Fulfilled ? $this->notifier->autoRenewed($order) : $this->notifier->renewalFailed($order);
    }

    /** The deadline of a service that is running and within the admin's days of it; null for any other. */
    private function dueDeadline(Subscription $subscription): ?Carbon
    {
        $deadline = $subscription->expires_at;
        $due = $subscription->isActive() && $deadline !== null && $deadline->isFuture()
            && $deadline->lessThanOrEqualTo(now()->addDays($this->settings->autoRenewDays()));

        return $due ? $deadline : null;
    }

    /**
     * The renewal order, made under a lock on the service's row and only while no renewal of it is open — being paid,
     * paid, in delivery, or paid but not delivered (a failed one waits for the admin's retry, never for a second charge)
     * — and while the switch is still on: two runs never both charge.
     */
    private function claim(Subscription $subscription, Plan $plan): ?Order
    {
        return $this->db->transaction(function () use ($subscription, $plan): ?Order {
            $row = $subscription->newModelQuery()->whereKey($subscription->id)->lockForUpdate()->first();
            $open = Order::query()
                ->where('subscription_id', $subscription->id)
                ->where('type', OrderType::Renewal->value)
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Paid->value, OrderStatus::Processing->value, OrderStatus::Failed->value])
                ->exists();
            if ($row === null || !$row->auto_renew || $open) {
                return null;
            }

            return $this->orders->createRenewal($subscription->user, $subscription, $plan);
        });
    }

    /**
     * The wallet cannot pay: the customer hears it once per renewal window — the window moves with the deadline, so the
     * next one tells them again —, the mark claimed first so two runs tell once.
     */
    private function short(Subscription $subscription, Carbon $deadline, string $price): void
    {
        if (Transitions::claim($subscription, 'renewal_notified_at', before: $deadline->copy()->subDays($this->settings->autoRenewDays()))) {
            $this->notifier->autoRenewShort($subscription, $price);
        }
    }
}
