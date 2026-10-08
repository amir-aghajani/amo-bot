<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * «♻️ تمدید سرویس»: a renewal the customer starts — in the bot, or on the shop's website — on the service's own plan at its
 * price today, paid any way the checkout offers (OrderService::openRenewal()) and delivered like every renewal
 * (ProvisioningService::renew()). Its customer may renew a service while it is active or ended — not one support
 * switched off, whose switch a renewal would turn back on, nor one gone from its panel — on a plan that still exists and
 * renews something (a term, or traffic); a plan taken off sale still renews the services sold on it, as «تمدید خودکار»
 * does. In an agent's bot their traffic must cover the plan, as for a sale (ServerSelector::covers()): the delivery
 * draws it. Before it is paid, both doors show the same preview of it (preview()).
 */
final class CustomerRenewal
{
    /** The states a service may be renewed in by its customer. */
    private const RENEWABLE = [SubscriptionStatus::Active, SubscriptionStatus::Expired];

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly RenewalSettings $settings,
    ) {}

    /** The plan the service is renewed on — its own —, while its customer may renew it now; null otherwise. */
    public function planFor(Subscription $subscription): ?Plan
    {
        return $this->plansFor([$subscription])[$subscription->id];
    }

    /**
     * The renewal's checkout, while its customer may renew the service now (planFor()): the plan, its price today, and
     * the service as the renewal would leave it — its end, its traffic and the traffic of the period in use that goes when
     * that period ends (the shop not carrying it: RenewalSettings) — by the row's last copy of its panel's numbers,
     * nothing saved and no panel asked (the delivery reads the panel first). Null while it may not be renewed.
     */
    public function preview(Subscription $subscription): ?RenewalPreview
    {
        $plan = $this->planFor($subscription);

        return $plan === null ? null : new RenewalPreview(
            $plan,
            Money::normalize($plan->price),
            ServiceTerms::renewal($subscription, $plan, $this->settings->carriesTraffic())->preview($subscription),
        );
    }

    /**
     * planFor() of several services at once — a page of a customer's on their website —, by id: an agent's traffic read
     * once for them all, and only for the plans that renew something.
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
        // In an agent's bot their traffic must cover it, as for a sale (the delivery draws it).
        $covered = $this->selector->coverage(array_filter($plans));

        return array_map(static fn(?Plan $plan): ?Plan => $plan !== null && $covered[$plan->id] ? $plan : null, $plans);
    }

    /**
     * The customer's services the menu's «♻️ تمدید سرویس» lists, newest first: active or ended, on a plan that still
     * exists and renews something. (Whether an agent's traffic covers one is asked as its renewal is opened.)
     *
     * @return Builder<Subscription>
     */
    public static function candidates(int $userId): Builder
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', array_map(static fn(SubscriptionStatus $status): string => $status->value, self::RENEWABLE))
            ->whereHas('plan', static fn(Builder $plan) => $plan->where(static fn(Builder $renews) => $renews->where('duration_days', '>', 0)->orWhere('traffic_gb', '>', 0)))
            ->latest('id');
    }

    /** A plan that gives a renewal something: a term, or traffic — one without either would sell nothing new. */
    private static function renewsSomething(Plan $plan): bool
    {
        return $plan->duration_days > 0 || $plan->trafficBytes() > 0;
    }
}
