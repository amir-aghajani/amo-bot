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
use App\Modules\Orders\Services\Pricing;
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
 * the admin's days (RenewalSettings): a renewal order at the customer's price, paid by the wallet (an agent's credit
 * counts), delivered like any renewal (ProvisioningService::renew()). The customer is notified of the outcome:
 * renewed, the wallet short (once per renewal window), or paid but not delivered (support finishes it).
 *
 * Only a running service with a deadline qualifies — one waiting for its first connection has none yet, one that never
 * ends needs none, an ended one is renewed by hand — and only on a plan whose term is at least the window (renews()):
 * a shorter term would leave the service due again at once. Two runs never charge a service twice (claim()).
 */
final class AutoRenewal
{
    /** `orders.notes` of a renewal the wallet could not pay after all (its balance dropped between check and charge). */
    private const NOTE_UNPAID = 'تمدید خودکار: موجودی کیف پول کافی نبود';

    public function __construct(
        private readonly RenewalSettings $settings,
        private readonly ProvisioningService $provisioning,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly PaymentMethods $methods,
        private readonly CustomerNotifier $notifier,
        private readonly Pricing $pricing,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the customer is offered the switch: the service could renew itself and the wallet, which pays, is on. */
    public function offeredFor(Subscription $subscription): bool
    {
        return $this->renews($subscription) && $this->walletOn();
    }

    /**
     * offeredFor() for a page of services, by id, asking about the wallet once.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, bool>
     */
    public function offeredForAll(iterable $subscriptions): array
    {
        $walletOn = $this->walletOn();
        $offered = [];
        foreach ($subscriptions as $subscription) {
            $offered[$subscription->id] = $walletOn && $this->renews($subscription);
        }

        return $offered;
    }

    /** Whether the wallet, which pays every automatic renewal, is on; without it nothing is renewed. */
    public function walletOn(): bool
    {
        return $this->methods->enabledWallet() !== null;
    }

    /**
     * Whether the service could renew itself: it runs, ends some day, and still has a plan whose term is at least the
     * renewal window (RenewalSettings::autoRenews()).
     */
    public function renews(Subscription $subscription): bool
    {
        $plan = $subscription->plan;

        return $subscription->isActive() && $subscription->duration_hours > 0 && $plan !== null && $this->settings->autoRenews($plan);
    }

    /**
     * Set the customer's switch (the bot's button, the website's), only while it is offered (offeredFor()).
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
     * The services to renew now, nearest deadline first: switch on, deadline within the admin's days, customer not
     * banned, on a plan whose term is at least the window.
     *
     * @return list<Subscription>
     */
    public function due(): array
    {
        return Subscription::expiringWithin($this->settings->autoRenewDays())
            ->where('auto_renew', true)
            ->whereHas('user', static fn(Builder $user) => $user->where('status', UserStatus::Active->value))
            ->whereHas('plan', fn(Builder $plan) => $plan->where('duration_hours', '>=', $this->settings->autoRenewHours()))
            ->with(['user', 'plan', 'server'])
            ->oldest('expires_at')
            ->get()->values()->all();
    }

    /**
     * Renew one service if it is still due, and tell the customer the outcome. The panel is asked first — its deadline is
     * the truth (an admin may have extended the client there) —; a panel out of reach or in backoff waits for the next
     * run with nothing charged. A customer whose wallet cannot pay is notified once per renewal window, and one whose
     * delivery broke on a bug (paid, the order failed for support's retry) too; that exception goes on up for the run
     * to log.
     */
    public function renew(Subscription $subscription): void
    {
        $wallet = $this->methods->enabledWallet();
        $plan = $subscription->plan;
        // A plan whose term was shortened below the window since is not renewed, whatever the switch says.
        if ($wallet === null || $plan === null || !$this->settings->autoRenews($plan) || $subscription->server->isBackingOff()) {
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

        // The customer's price (agent price where theirs, less the automatic discount), as createRenewal() orders it.
        $price = $this->pricing->renewal($subscription->user, $subscription, $plan)->amount();
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
            // Paid, but the delivery broke on a bug: the order failed for support's retry, and the customer hears it as
            // any renewal paid but not delivered. The exception goes on to the run's log.
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
     * Make the renewal order under a lock on the service's row, only while the switch is still on and no renewal of it is
     * open — pending, paid, processing, or failed (a failed one waits for support's retry, never a second charge) — so
     * two runs never both charge.
     */
    private function claim(Subscription $subscription, Plan $plan): ?Order
    {
        return $this->db->transaction(function () use ($subscription, $plan): ?Order {
            $row = $subscription->newModelQuery()->whereKey($subscription->id)->lockForUpdate()->first();
            $open = Order::query()
                ->where('subscription_id', $subscription->id)
                ->where('type', OrderType::Renewal->value)
                ->whereIn('status', Order::OPEN)
                ->exists();
            if ($row === null || !$row->auto_renew || $open) {
                return null;
            }

            return $this->orders->createRenewal($subscription->user, $subscription, $plan);
        });
    }

    /**
     * The wallet cannot pay: tell the customer once per renewal window (the window moves with the deadline, so the next
     * one tells them again). The mark is claimed first, so two runs tell once.
     */
    private function short(Subscription $subscription, Carbon $deadline, string $price): void
    {
        if (Transitions::claim($subscription, 'renewal_notified_at', before: $deadline->copy()->subDays($this->settings->autoRenewDays()))) {
            $this->notifier->autoRenewShort($subscription, $price);
        }
    }
}
