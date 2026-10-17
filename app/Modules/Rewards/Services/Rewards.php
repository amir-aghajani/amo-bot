<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Agency\Services\TrafficPool;
use App\Modules\Auth\Actor;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Services\PaymentMethods;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Providers\Exceptions\ProviderException;
use App\Modules\Rewards\DTO\Given;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ProvisioningService;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\WalletService;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Delivers every prize or gift to a customer — the wheel's, the nightly draw's, a gift code's, the referral
 * program's:
 * - Toman: a wallet line;
 * - traffic or days: granted on a running service of theirs (ProvisioningService::grant()); in an agent's shop the GB
 *   come out of the agent's pool;
 * - a plan: a free service — a purchase order at no price (`pricing` gift) on the plan's first server that can deliver
 *   it, settled by the shop's wallet row taking nothing, then delivered like any order (a failed delivery waits for
 *   support's retry: given all the same); never of a plan the owner keeps from an agent's sale (Plan::$hidden_by_owner).
 * The caller says what the reward was for, and words a panel failure while granting. An agent's shop whose selling is
 * suspended (Bot::sells()) gives only Toman.
 */
final class Rewards
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly ProvisioningService $provisioning,
        private readonly TrafficPool $pool,
        private readonly ServerSelector $selector,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly PaymentMethods $methods,
        private readonly CustomerNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Gives `$reward` to the customer. `$reason` is the wallet line's words; `$service` is where traffic or days go (else
     * their newest service that takes them); `$tell` sends a plan's service to the customer once delivered.
     *
     * @throws RewardUnavailableException nothing was given: no running service takes it, no server delivers the plan, or
     *                                    the shop's selling is suspended
     */
    public function give(User $user, Reward $reward, string $reason, ?Subscription $service = null, bool $tell = true): Given
    {
        // An agent's shop whose selling is suspended gives no service, traffic or days — Toman to the wallet still. Read
        // afresh (Bot::sellsNow()): the bot's poller keeps a shop's row a while.
        if ($reward->kind->isSold() && !$user->shop()->sellsNow()) {
            throw new RewardUnavailableException(RewardUnavailableException::SUSPENDED);
        }
        $given = match ($reward->kind) {
            RewardKind::Nothing => new Given($reward),
            RewardKind::Wallet => $this->credit($user, $reward, $reason),
            RewardKind::Traffic, RewardKind::Days => $this->onService($user, $reward, $service),
            RewardKind::Plan => $this->plan($user, $reward),
        };
        $this->logger->info('Reward given to user {user}: {reward} ({reason})', ['user' => $user->id, 'reward' => $reward->words(), 'reason' => $reason]);
        if ($tell) {
            $this->sendService($given);
        }

        return $given;
    }

    /**
     * Sends the customer the service a plan reward made, once delivered (as any paid order's service is sent). A failed
     * delivery waits for support's retry, which tells them. Nothing for other rewards.
     */
    public function sendService(Given $given): void
    {
        if ($given->payment !== null && $given->order?->status === OrderStatus::Fulfilled) {
            $this->notifier->paymentSettled($given->payment);
        }
    }

    /**
     * How a prize that could not be given is logged (the wheel's, the nightly draw's): a panel's or a rule's refusal is
     * expected, a warning; anything else is a bug, an error.
     */
    public static function failureLevel(\Throwable $e): string
    {
        return $e instanceof DomainRuleException || $e instanceof ProviderException ? LogLevel::WARNING : LogLevel::ERROR;
    }

    /** The customer's newest running service that takes `$reward` (takes()); null for none, or a reward of no service. */
    public function serviceFor(User $user, Reward $reward): ?Subscription
    {
        if (!$reward->kind->onService()) {
            return null;
        }

        return Subscription::active()
            ->with(['server', 'user', 'plan'])
            ->where('user_id', $user->id)
            ->latest('id')
            ->get()
            ->first(static fn(Subscription $subscription): bool => self::takes($subscription, $reward));
    }

    /**
     * Whether the service can take it: active and no free test (removed once it ends), and for days one that ends some day
     * with traffic left — no later, with them, than the last day the shop keeps (ServiceTerms::daysLeft()) —, for traffic
     * one with a quota and time left.
     */
    private static function takes(Subscription $subscription, Reward $reward): bool
    {
        return $subscription->isActive() && !$subscription->is_test && match ($reward->kind) {
            RewardKind::Days => $subscription->hasTerm() && !$subscription->isExpiredByTraffic() && ServiceTerms::daysLeft($subscription) >= $reward->dayCount(),
            RewardKind::Traffic => $subscription->traffic_limit_bytes > 0 && !$subscription->isExpiredByTime(),
            default => false,
        };
    }

    private function credit(User $user, Reward $reward, string $reason): Given
    {
        $this->wallet->credit($user, $reward->amount ?? '0', $reason);

        return new Given($reward);
    }

    /** @throws RewardUnavailableException */
    private function onService(User $user, Reward $reward, ?Subscription $service): Given
    {
        $service ??= $this->serviceFor($user, $reward);
        if ($service === null || $service->user_id !== $user->id || !self::takes($service, $reward)) {
            throw new RewardUnavailableException(RewardUnavailableException::NO_SERVICE);
        }

        $service->loadMissing(['server', 'user', 'plan']);
        $give = fn() => $this->provisioning->grant($service, $reward->dayCount(), $reward->bytes());
        if ($reward->bytes() > 0 && !$service->shop()->isMain()) {
            $this->pool->extending($service, Actor::system(), $reward->bytes(), $give);
        } else {
            $give();
        }

        return new Given($reward, subscription: $service->refresh());
    }

    /**
     * The plan's free service. Once its order is paid the service is given, whatever its delivery does: one that broke on
     * a bug (OrderService::DELIVERY_BROKEN, rethrown) waits for support's retry as a panel's failure does — logged here,
     * never thrown, so no caller gives the prize again (a spin refunded, a milestone freed, the next winner drawn).
     *
     * @throws RewardUnavailableException a plan the owner keeps from an agent's sale, one no server delivers now, or one
     *                                    whose term would end the service past the last day the shop keeps
     */
    private function plan(User $user, Reward $reward): Given
    {
        $plan = $reward->plan ?? throw new \LogicException('A plan reward without its plan.');
        if (!ServiceTerms::startsInTime($plan->duration_hours)) {
            throw new RewardUnavailableException(TermTooLongException::message());
        }
        $choice = $plan->hidden_by_owner ? null : ($this->selector->choices($plan)[0] ?? null);
        if ($choice === null) {
            throw new RewardUnavailableException(RewardUnavailableException::NO_SERVER);
        }

        $order = $this->orders->openGift($user, $plan, $choice['server']);
        try {
            ['payment' => $payment] = $this->payments->createForOrder($order, $this->methods->wallet());
        } catch (\Throwable $e) {
            $payment = $order->refresh()->load('payments')->paidBy() ?? throw $e;
            $this->logger->error('The free service of order {id} was paid but its delivery broke: it waits for support\'s retry ({message})', ['id' => $order->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }

        return new Given($reward, subscription: $order->refresh()->subscription, order: $order, payment: $payment);
    }
}
