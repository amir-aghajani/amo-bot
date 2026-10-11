<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * «♻️ تمدید سرویس»: a renewal the customer starts in the bot or on the website, on the service's own plan at today's
 * price for them (Pricing), paid through the checkout (OrderService::openRenewal()) and delivered like every renewal
 * (ProvisioningService::renew()).
 *
 * A service may be renewed while active or ended — not one support switched off (a renewal would switch it back on),
 * nor one gone from its panel — on a plan that still exists and gives something (a term or traffic). A plan taken off
 * sale still renews what was sold on it. In an agent's bot the agent's traffic must cover the plan, as for a sale
 * (ServerSelector::covers()), since the delivery draws it. The bot and the website show the same preview first (preview()).
 */
final class CustomerRenewal
{
    /** The states a service may be renewed in by its customer. */
    private const RENEWABLE = [SubscriptionStatus::Active, SubscriptionStatus::Expired];

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly RenewalSettings $settings,
        private readonly Pricing $pricing,
    ) {}

    /** The plan the service renews on (its own) while its customer may renew it now; null otherwise. */
    public function planFor(Subscription $subscription): ?Plan
    {
        return $this->plansFor([$subscription])[$subscription->id];
    }

    /**
     * The renewal's checkout while the service may be renewed (planFor()): the plan, today's price for the customer
     * (Pricing::renewal(), what OrderService::openRenewal() orders at without a code), and the service as the renewal
     * would leave it — from the row's last copy of the panel's numbers, nothing saved and no panel asked (the delivery
     * reads the panel first). Null while it may not be renewed.
     */
    public function preview(Subscription $subscription): ?RenewalPreview
    {
        $plan = $this->planFor($subscription);
        if ($plan === null) {
            return null;
        }
        $quote = $this->pricing->renewal($subscription->user, $subscription, $plan);

        return new RenewalPreview(
            $plan,
            $quote->amount(),
            ServiceTerms::renewal($subscription, $plan, $this->settings->carriesTraffic())->preview($subscription),
            $quote,
        );
    }

    /**
     * planFor() for a page of services, by id, reading an agent's traffic once.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, Plan|null>
     */
    public function plansFor(iterable $subscriptions): array
    {
        $plans = [];
        foreach ($subscriptions as $subscription) {
            $plan = $subscription->plan;
            $plans[$subscription->id] = $plan !== null && in_array($subscription->status, self::RENEWABLE, true) && self::renewsSomething($plan) ? $plan : null;
        }
        // In an agent's bot the agent's traffic must cover it, as for a sale: the delivery draws it.
        $covered = $this->selector->coverage(array_filter($plans));

        return array_map(static fn(?Plan $plan): ?Plan => $plan !== null && $covered[$plan->id] ? $plan : null, $plans);
    }

    /**
     * The customer's services the bot's «♻️ تمدید سرویس» lists, newest first: active or ended, on a plan that still
     * exists and gives something. Whether an agent's traffic covers one is checked when its renewal is opened.
     *
     * @return Builder<Subscription>
     */
    public static function candidates(int $userId): Builder
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', array_map(static fn(SubscriptionStatus $status): string => $status->value, self::RENEWABLE))
            ->whereHas('plan', static fn(Builder $plan) => $plan->where(static fn(Builder $renews) => $renews->where('duration_hours', '>', 0)->orWhere('traffic_gb', '>', 0)))
            ->latest('id');
    }

    /** A plan that gives a renewal something: a term or traffic. */
    private static function renewsSomething(Plan $plan): bool
    {
        return $plan->duration_hours > 0 || $plan->trafficBytes() > 0;
    }
}
