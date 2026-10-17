<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Transitions;
use App\Modules\Bots\CurrentBot;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Exceptions\OrderNotPayableException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Exceptions\AutoRenewUnavailableException;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Tasks\AutoRenewTask;
use App\Modules\Users\Enums\UserStatus;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * «تمدید خودکار»: a service whose customer turned the switch on is renewed from their wallet once its deadline is within
 * the admin's days (RenewalSettings): a renewal order at the customer's price by the admin's default method
 * (RenewalSettings::defaultMethod()), paid by the wallet (an agent's credit counts), delivered like any renewal
 * (ProvisioningService::renew()); the window a customer renews in by hand (CustomerRenewal::window()) does not hold it
 * back. The customer is notified of the outcome: renewed, the wallet short (once per renewal window), or paid but not
 * delivered (support finishes it).
 *
 * Only a running service with a deadline qualifies — one waiting for its first connection has none yet, one that never
 * ends needs none, an ended one is renewed by hand — and only on a plan whose term is at least the window (renews()):
 * a shorter term would leave the service due again at once; and only at a renewal price within the wallet's least and
 * most amount — one outside them is renewed by hand —, and while the renewal ends it by the last day the shop keeps
 * (endsInTime(); one that would not is reminded of its end, as one outside the wallet's bounds is). By ResetTime, which
 * starts the term afresh and throws away what was left of it, the renewal waits for the last run before the deadline
 * (LAST_RUN_MINUTES), so it loses no more than that; a wallet that cannot pay is still told at the window's start. A
 * deadline is renewed once, and two runs never charge a service twice (claim()).
 */
final class AutoRenewal
{
    /**
     * How near its deadline a renewal by ResetTime waits to be: the last run before it — the task's interval
     * (AutoRenewTask::INTERVAL_MINUTES), and a few minutes for a run that comes late (the scheduler's minute, the shops
     * before this one) —, so the term it throws away is that much at most.
     */
    public const LAST_RUN_MINUTES = AutoRenewTask::INTERVAL_MINUTES + 5;

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

    /** Whether the customer is offered the switch: the wallet, which pays, is on and could renew the service (renews()). */
    public function offeredFor(Subscription $subscription): bool
    {
        return $this->offeredForAll([$subscription])[$subscription->id];
    }

    /**
     * offeredFor() for a page of services, by id, asking about the wallet once.
     *
     * @param iterable<Subscription> $subscriptions Read with their customer and plan
     * @return array<int, bool>
     */
    public function offeredForAll(iterable $subscriptions): array
    {
        return $this->renewing($subscriptions, $this->wallet());
    }

    /** The wallet while it is switched on: it pays every automatic renewal, and without it nothing is renewed. */
    public function wallet(): ?PaymentMethod
    {
        return $this->methods->enabledWallet();
    }

    /**
     * Whether the service could renew itself, paid by `$wallet` (wallet(); none renews nothing): it runs, ends some day,
     * still has a plan whose term is at least the renewal window (RenewalSettings::autoRenews()) and whose renewal ends
     * it by the last day the shop keeps (endsInTime()), and the wallet takes its renewal price (PaymentMethods::within())
     * — one it does not take is renewed by hand. One that could not is reminded of its end (ServiceReminders).
     */
    public function renews(Subscription $subscription, ?PaymentMethod $wallet): bool
    {
        return $this->renewing([$subscription], $wallet)[$subscription->id];
    }

    /**
     * renews() for a page of services, by id, each customer's prices read once — and only while the wallet has a bound
     * at all: their renewal price on the plan, as renew() charges it (Pricing::renewalOffers(): an agent's at the agent
     * price, less the automatic discount).
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, bool>
     */
    private function renewing(iterable $subscriptions, ?PaymentMethod $wallet): array
    {
        $renews = [];
        $priced = [];
        foreach ($subscriptions as $subscription) {
            $renews[$subscription->id] = false;
            $plan = $subscription->plan;
            if ($subscription->isActive() && $subscription->duration_hours > 0 && $plan !== null && $this->settings->autoRenews($plan) && $this->endsInTime($subscription, $plan)) {
                $priced[$subscription->user_id][] = [$subscription, $plan];
            }
        }
        if ($wallet === null) {
            return $renews;
        }

        $bounded = $wallet->min_amount !== null || $wallet->max_amount !== null;
        foreach ($priced as $services) {
            $prices = $bounded ? $this->pricing->renewalOffers($services[0][0]->user, ...array_column($services, 1)) : null;
            foreach ($services as [$subscription, $plan]) {
                $renews[$subscription->id] = $prices === null || PaymentMethods::within($wallet, $prices[$plan->id]->amount());
            }
        }

        return $renews;
    }

    /**
     * Set the customer's switch (the bot's button, the website's), only while it is offered (offeredFor()) and the
     * service is still theirs: one handed to another customer since it was read is the new owner's wallet to commit.
     *
     * @throws AutoRenewUnavailableException 422 on `auto_renew`
     */
    public function set(Subscription $subscription, bool $on): void
    {
        if (!$this->offeredFor($subscription)) {
            throw new AutoRenewUnavailableException();
        }
        if ($subscription->newModelQuery()->whereKey($subscription->id)->where('user_id', $subscription->user_id)->update(['auto_renew' => $on]) !== 1) {
            throw new AutoRenewUnavailableException();
        }
        $subscription->forceFill(['auto_renew' => $on])->syncOriginalAttribute('auto_renew');
    }

    /**
     * The services to renew now, nearest deadline first: switch on, deadline within the admin's days, customer not
     * banned, on a plan whose term is at least the window — by ResetTime, renew() lets them wait for the last run. None
     * in an agent's shop whose selling is suspended (a renewal is a sale): they wait, and are renewed once it sells again
     * if still within their days.
     *
     * @return list<Subscription>
     */
    public function due(): array
    {
        if (!CurrentBot::get()->sells()) {
            return [];
        }

        return Subscription::expiringWithin($this->settings->autoRenewDays())
            ->where('auto_renew', true)
            ->whereHas('user', static fn(Builder $user) => $user->where('status', UserStatus::Active->value))
            ->whereHas('plan', fn(Builder $plan) => $plan->where('duration_hours', '>=', $this->settings->autoRenewHours()))
            ->with(['user', 'plan', 'server'])
            ->oldest('expires_at')
            ->get()->values()->all();
    }

    /**
     * Renew one service if it is still due by the admin's default method, and tell the customer the outcome. The panel is
     * asked first — its deadline is the truth (an admin may have extended the client there) —; a panel out of reach or
     * in backoff waits for the next run with nothing charged. A renewal by ResetTime waits for the last run before the
     * deadline (renewsNow()), its panel not asked meanwhile. A customer whose wallet cannot pay is notified once per
     * renewal window, from its start whatever the method, and one whose delivery broke on a bug (paid, the order failed
     * for support's retry) too; that exception goes on up for the run to log.
     */
    public function renew(Subscription $subscription): void
    {
        $wallet = $this->wallet();
        $plan = $subscription->plan;
        // A plan whose term was shortened below the window since is not renewed, whatever the switch says — nor one whose
        // renewal would end the service past the last day the shop keeps: its panel is not asked, nor a short wallet told.
        if ($wallet === null || $plan === null || !$this->settings->autoRenews($plan) || !$this->endsInTime($subscription, $plan) || $subscription->server->isBackingOff()) {
            return;
        }

        // The customer's price (agent price where theirs, less the automatic discount), as createRenewal() orders it — and
        // never a price outside the wallet's bounds (PaymentMethods::within()), which the wallet would refuse at checkout:
        // that service is not offered the switch (renews()), and its customer is reminded of its end (ServiceReminders).
        $price = $this->pricing->renewal($subscription->user, $subscription, $plan)->amount();
        if (!PaymentMethods::within($wallet, $price)) {
            return;
        }
        $pays = Money::compare($subscription->user->refresh()->spendable(), $price) >= 0;
        $method = $this->settings->defaultMethod();
        // Not its method's time yet (ResetTime's last run): its panel is not asked — but a short wallet is told from now.
        if ($pays && !$this->renewsNow($subscription->expires_at, $method)) {
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
        if (!$pays) {
            $this->short($subscription, $deadline, $price);

            return;
        }
        if (!$this->renewsNow($deadline, $method)) {
            return;
        }
        // Judged again by the panel's deadline just read.
        if (!$this->endsInTime($subscription, $plan)) {
            $this->logger->info('Auto-renewal of subscription {id} is not made: it would end the service past the last day the shop keeps', ['id' => $subscription->id]);

            return;
        }

        $order = $this->claim($subscription, $plan, $method);
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

    /**
     * Whether the renewal on `$plan` by the admin's default method would end the service by the last day the shop keeps
     * (ServiceTerms::renewal()), by the row as it stands: one that would not renews nothing by itself — nothing charged,
     * the switch not offered (renews()) —, and its customer is reminded of its end instead, to hear why when they renew
     * by hand. Asked again once the panel's numbers are read into the row.
     *
     * @phpstan-impure
     */
    private function endsInTime(Subscription $subscription, Plan $plan): bool
    {
        try {
            ServiceTerms::renewal($subscription, $plan->package(), $this->settings->carriesTraffic(), $this->settings->defaultMethod());
        } catch (TermTooLongException) {
            return false;
        }

        return true;
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
     * When a renewal by `$method` of a service due at `$deadline` may be made from: the admin's days before it — by
     * ResetTime, which throws away the term left, only from the last run before it (LAST_RUN_MINUTES).
     */
    private function opensAt(Carbon $deadline, RenewalMethod $method): Carbon
    {
        return $method === RenewalMethod::ResetTime
            ? $deadline->copy()->subMinutes(self::LAST_RUN_MINUTES)
            : $deadline->copy()->subDays($this->settings->autoRenewDays());
    }

    /** Whether a service due at `$deadline` is renewed by `$method` now (opensAt()); one without a deadline is not. */
    private function renewsNow(?Carbon $deadline, RenewalMethod $method): bool
    {
        return $deadline !== null && now()->greaterThanOrEqualTo($this->opensAt($deadline, $method));
    }

    /**
     * Make the renewal order by `$method` under a lock on the service's row, only while the switch is still on, the row
     * still due by it now (another renewal may have moved its deadline since it was read), and no renewal of it by its
     * owner either is open — pending, paid, processing, or failed (a failed one waits for support's retry, never a
     * second charge) — or was delivered since this deadline's renewal could be made (opensAt(); refunded since or not,
     * this task's or the customer's own): one that left the deadline where it was (a panel that kept its own) is not
     * followed by another. So a deadline is renewed once, and two runs never both charge. One a previous owner left is
     * never delivered onto it, so it holds nothing — and a service handed to another customer since the run read it is
     * not ordered for the one it read, whose wallet would pay for someone else's service.
     */
    private function claim(Subscription $subscription, Plan $plan, RenewalMethod $method): ?Order
    {
        return $this->db->transaction(function () use ($subscription, $plan, $method): ?Order {
            $row = $subscription->newModelQuery()->whereKey($subscription->id)->lockForUpdate()->first();
            $deadline = $row !== null && $row->auto_renew && (int) $row->user_id === (int) $subscription->user_id ? $this->dueDeadline($row) : null;
            if ($row === null || $deadline === null || !$this->renewsNow($deadline, $method)) {
                return null;
            }
            $renewals = Order::query()
                ->where('subscription_id', $subscription->id)
                ->where('user_id', $row->user_id)
                ->where('type', OrderType::Renewal->value);
            $held = (clone $renewals)->whereIn('status', Order::OPEN)->exists()
                || $renewals->where('fulfilled_at', '>=', $this->opensAt($deadline, $method))->exists();
            if ($held) {
                return null;
            }

            return $this->orders->createRenewal($subscription->user, $subscription, $plan, $method);
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
