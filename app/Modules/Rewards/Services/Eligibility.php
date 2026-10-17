<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Modules\Users\Models\User;

/**
 * Whether a reward can reach a customer now, asked before anything is spent on it (a wheel slice before the draw, a gift
 * code's reward before the code is taken). In an agent's shop whose selling is suspended only Toman (or nothing) can:
 * Rewards::give() gives nothing else there. Traffic or days need a running service that takes them on a server not
 * backing off (and, for traffic in an agent's shop, enough in the pool); a plan needs a server that delivers it and, in
 * an agent's shop, traffic that covers it. Rewards::give() still has the last word.
 */
final class Eligibility
{
    public function __construct(
        private readonly Rewards $rewards,
        private readonly ServerSelector $selector,
    ) {}

    /**
     * The rewards of `$rewards` that can reach the customer now, keys kept; the customer's service is looked up once per
     * kind.
     *
     * @template K of array-key
     * @param array<K, Reward> $rewards
     * @return array<K, Reward>
     */
    public function givable(User $user, array $rewards): array
    {
        // Asked of the shop once — read afresh (Bot::sellsNow()) —, and only for a reward it sells.
        $sells = null;
        /** @var array<string, Subscription|null> $services */
        $services = [];
        $givable = [];
        foreach ($rewards as $key => $reward) {
            if ($reward->kind->isSold() && !($sells ??= $user->shop()->sellsNow())) {
                continue;
            }
            if ($reward->kind->onService() && !array_key_exists($reward->kind->value, $services)) {
                $services[$reward->kind->value] = $this->rewards->serviceFor($user, $reward);
            }
            $ok = match ($reward->kind) {
                RewardKind::Nothing, RewardKind::Wallet => true,
                RewardKind::Traffic, RewardKind::Days => $this->onService($services[$reward->kind->value], $reward),
                RewardKind::Plan => $reward->plan !== null && $this->delivers($reward->plan),
            };
            if ($ok) {
                $givable[$key] = $reward;
            }
        }

        return $givable;
    }

    /**
     * Whether a server delivers the plan today — its term ending by the last day the shop keeps
     * (ServiceTerms::startsInTime()) —, and in an agent's shop the agent's traffic covers it.
     */
    private function delivers(Plan $plan): bool
    {
        return ServiceTerms::startsInTime($plan->duration_hours) && $this->selector->choices($plan) !== [] && $this->selector->covers($plan);
    }

    private function onService(?Subscription $service, Reward $reward): bool
    {
        if ($service === null || $service->server->isBackingOff()) {
            return false;
        }
        $shop = $service->shop();

        return $reward->bytes() === 0 || $shop->isMain() || $shop->trafficBalance() >= $reward->bytes();
    }
}
