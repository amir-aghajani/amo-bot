<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Catalog\Services\CustomServices;
use App\Modules\Catalog\Services\ServerSelector;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\Pricing;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\DTO\RenewalWindow;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\RenewalRefusal;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\TermTooLongException;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * «♻️ تمدید سرویس»: a renewal the customer starts in the bot or on the website, on the service's own plan at today's
 * price for them (Pricing), paid through the checkout (OrderService::openPreviewed()) and delivered like every renewal
 * (ProvisioningService::renew()).
 *
 * A service may be renewed while active or ended — not one support switched off (a renewal would switch it back on),
 * nor one gone from its panel — on a plan that still exists and gives something (a term or traffic). A plan taken off
 * sale still renews what was sold on it. In an agent's bot the agent's traffic must cover the plan, as for a sale
 * (ServerSelector::covers()), since the delivery draws it. The bot and the website show the same preview first (preview()).
 *
 * A service without a plan that is no free test — one the customer claimed by its link (ServiceClaims), or built
 * («سرویس دلخواه») — is renewed on a plan they pick among those on sale on its server (choices()); the renewal makes it
 * the service's plan, so the next one (and «تمدید خودکار») is on it. While the builder is offered (buildable()) they may
 * build the package instead, its GB and days by the builder's tiers and with its devices: the service keeps having no
 * plan, so «تمدید خودکار» never renews it.
 *
 * The admin may hold a running service back until it nears its end (window()), and offers one or more ways a renewal
 * adds its package (methods(), Enums\RenewalMethod): the customer picks among them, a checkout starting on the first.
 * Both are kept by preview(), the one door every renewal checkout goes through; when it opens none, refusal() says why
 * (Enums\RenewalRefusal), which the bot and the website only put in their words.
 */
final class CustomerRenewal
{
    /** The states a service may be renewed in by its customer. */
    private const RENEWABLE = [SubscriptionStatus::Active, SubscriptionStatus::Expired];

    public function __construct(
        private readonly ServerSelector $selector,
        private readonly RenewalSettings $settings,
        private readonly Pricing $pricing,
        private readonly CustomServices $custom,
        private readonly OrderService $orders,
    ) {}

    /** The plan the service renews on (its own) while its customer may renew it now; null otherwise. */
    public function planFor(Subscription $subscription): ?Plan
    {
        return $this->plansFor([$subscription])[$subscription->id];
    }

    /**
     * The plans a service without one may be renewed on now (picksPlan()): the active plans sold on its server, in the
     * catalogue's order; none for any other service.
     *
     * @return Collection<int, Plan>
     */
    public function choices(Subscription $subscription): Collection
    {
        return self::picksPlan($subscription) ? $this->onSale([(int) $subscription->server_id])[(int) $subscription->server_id] : new Collection();
    }

    /**
     * The renewal's checkout while the service may be renewed, its window open (window()) and no renewal of it under way,
     * with `$with`: on its own plan (null, planFor()); or — a service without one — on the plan of that id when it is
     * among its choices(), or with the package built at that price (CustomServices::quote()) while it is buildable().
     * What is named is what it renews with or nothing: a plan or a package named for a service that has another plan now
     * (a page drawn before its first renewal) gives no preview, so nobody pays for what they did not see. By `$method`
     * (resolveMethod()). What it renews with and gives, today's price for the customer (Pricing::renewal(), what
     * OrderService orders at without a code), and the service as the renewal would leave it — from the row's last copy
     * of the panel's numbers, nothing saved and no panel asked (the delivery reads the panel first). Null while there is
     * none: refusal() says why.
     *
     * @throws TermTooLongException when the renewal would end the service past the last day the shop keeps
     */
    public function preview(Subscription $subscription, int|CustomPrice|null $with = null, ?RenewalMethod $method = null): ?RenewalPreview
    {
        $method = $this->resolveMethod($method);
        $renewsWith = $method === null ? RenewalRefusal::MethodNotOffered : $this->renewsWith($subscription, $with)[0];
        if ($renewsWith instanceof RenewalRefusal) {
            return null;
        }

        $package = $renewsWith instanceof Plan ? $renewsWith->package() : $this->custom->package($renewsWith->gb, $renewsWith->days);
        // With the devices a service waiting for its first connection keeps on top, as its delivery adds them.
        $after = ServiceTerms::renewal($subscription, $package, $this->settings->carriesTraffic(), $method, CustomerExtras::devicesBought($subscription))->preview($subscription);

        return new RenewalPreview($renewsWith, $package, $after, $this->pricing->renewal($subscription->user, $subscription, $renewsWith), $method, $this->methods());
    }

    /**
     * What the customer may renew the service on now, to pick from: its own plan — or, one without, its choices() — and
     * whether a package they build may renew it instead (buildable()); nothing while it may not be renewed now
     * (refusal(): not at all, not yet, or a renewal under way). Nothing is priced: the caller prices the plans once.
     *
     * @return array{plans: list<Plan>, builds: bool}
     */
    public function picks(Subscription $subscription): array
    {
        [$renewsWith, $choices] = $this->renewsWith($subscription, null);

        return match (true) {
            $renewsWith instanceof Plan => ['plans' => [$renewsWith], 'builds' => false],
            $renewsWith instanceof RenewalRefusal && $renewsWith->picking() => ['plans' => $choices->all(), 'builds' => $renewsWith === RenewalRefusal::PickAPlanOrPackage],
            default => ['plans' => [], 'builds' => false],
        };
    }

    /**
     * The renewal `$preview` shows priced with the discount code its customer typed — refused as Pricing::renewal()
     * refuses one that does not hold —; none, its own quote.
     */
    public function priced(Subscription $subscription, RenewalPreview $preview, ?string $code): Quote
    {
        return $code === null ? $preview->quote : $this->pricing->renewal($subscription->user, $subscription, $preview->with, $code);
    }

    /**
     * Why preview() gives the service no checkout with `$with` by `$method`, in RenewalRefusal's order; null when it gives
     * one. Asked without pricing anything.
     */
    public function refusal(Subscription $subscription, int|CustomPrice|null $with = null, ?RenewalMethod $method = null): ?RenewalRefusal
    {
        if ($this->resolveMethod($method) === null) {
            return RenewalRefusal::MethodNotOffered;
        }
        $renewsWith = $this->renewsWith($subscription, $with)[0];

        return $renewsWith instanceof RenewalRefusal ? $renewsWith : null;
    }

    /**
     * The method a renewal checkout is by: `$named` while the shop offers it, the first it offers when none is named;
     * null for one it does not offer.
     */
    public function resolveMethod(?RenewalMethod $named): ?RenewalMethod
    {
        $offered = $this->methods();

        return $named === null ? $offered[0] : (in_array($named, $offered, true) ? $named : null);
    }

    /**
     * Whether the service may be renewed now with a package its customer builds («سرویس دلخواه»): one without a plan
     * (picksPlan()) while the builder is offered (CustomServices::offered()).
     */
    public function buildable(Subscription $subscription): bool
    {
        return self::picksPlan($subscription) && $this->custom->offered();
    }

    /**
     * The ways the shop lets a customer renew by (RenewalSettings::methods()), the one a checkout starts on first.
     *
     * @return non-empty-list<RenewalMethod>
     */
    public function methods(): array
    {
        return $this->settings->methods();
    }

    /**
     * Whether the shop's window lets the customer renew the service now (RenewalSettings::windowDays(), windowPercent()),
     * and what opens it otherwise. Any time once it has ended — its status says so, or it is past its deadline or out
     * of traffic before a sync did —, or while the shop holds no service back (both 0). A running one once its deadline
     * is within the window's days, or once at most the window's percent of its traffic is left: one whose clock has not
     * started has no deadline to come near yet, and only its traffic lets it in before then. One that never ends by time
     * has no deadline to wait for: only its traffic holds it back — with no percent for it (none set, or no quota), it is
     * let in at once, the window's days being nothing it could come within. A service switched off or gone is no matter of
     * the window (planFor() refuses it). Automatic renewal goes by its own days.
     */
    public function window(Subscription $subscription): RenewalWindow
    {
        $days = $this->settings->windowDays();
        $percent = $this->settings->windowPercent();
        $runs = $subscription->isActive() && !$subscription->isExpiredByTime() && !$subscription->isExpiredByTraffic();
        $limit = $subscription->traffic_limit_bytes;
        $byTraffic = $percent > 0 && $limit > 0;
        if (!$runs || ($days === 0 && $percent === 0) || (!$subscription->hasTerm() && !$byTraffic)) {
            return new RenewalWindow(true);
        }

        $deadline = $subscription->expires_at;
        $opensAt = $days > 0 && $deadline !== null ? $deadline->copy()->subDays($days) : null;
        if (($opensAt !== null && $opensAt->lessThanOrEqualTo(now())) || ($byTraffic && ($subscription->remainingBytes() ?? 0) * 100 <= $limit * $percent)) {
            return new RenewalWindow(true);
        }

        return new RenewalWindow(false, $opensAt, $days > 0 && $subscription->awaitsFirstUse() ? $days : null, $byTraffic ? $percent : null);
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
     * Whether each service may be renewed now on what its customer picks, by id: one without a plan (picksPlan()) while
     * a plan on sale is sold on its server (choices()) or the builder is offered (buildable()) — the plans and the
     * builder read once for the page. A service renewed on its own plan is plansFor()'s.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, bool>
     */
    public function pickable(iterable $subscriptions): array
    {
        $picking = [];
        $pickable = [];
        foreach ($subscriptions as $subscription) {
            $pickable[$subscription->id] = false;
            if (self::picksPlan($subscription)) {
                $picking[$subscription->id] = (int) $subscription->server_id;
            }
        }
        $builds = $picking !== [] && $this->custom->offered();
        $onSale = $builds ? [] : $this->onSale(array_values(array_unique($picking)));
        foreach ($picking as $id => $serverId) {
            $pickable[$id] = $builds || $onSale[$serverId]->isNotEmpty();
        }

        return $pickable;
    }

    /**
     * The customer's services the bot's «♻️ تمدید سرویس» lists, newest first: active or ended, on a plan that still
     * exists and gives something — or without a plan, to pick one (no free test). Whether an agent's traffic covers one,
     * or a plan is on sale for one to pick, is checked when its renewal is opened.
     *
     * @return Builder<Subscription>
     */
    public static function candidates(int $userId): Builder
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereIn('status', array_map(static fn(SubscriptionStatus $status): string => $status->value, self::RENEWABLE))
            ->where(static fn(Builder $renews) => $renews
                ->whereHas('plan', static fn(Builder $plan) => $plan->where(static fn(Builder $gives) => $gives->where('duration_hours', '>', 0)->orWhere('traffic_gb', '>', 0)))
                ->orWhere(static fn(Builder $picks) => $picks->whereNull('plan_id')->where('is_test', false)))
            ->latest('id');
    }

    /** Whether the service renews on a plan its customer picks: it has none, is no free test, and may be renewed now. */
    public static function picksPlan(Subscription $subscription): bool
    {
        return $subscription->plan_id === null && !$subscription->is_test && in_array($subscription->status, self::RENEWABLE, true);
    }

    /**
     * The active plans that give a renewal something, by each of the servers given that they are sold on — read once.
     * Whether a server could take a new sale now (its room, its switch, its inbounds) is no matter: a renewal makes no
     * new client. In an agent's shop the agent's traffic must cover the plan, as for any renewal (ServerSelector::coverage()).
     *
     * @param list<int> $serverIds
     * @return array<int, Collection<int, Plan>>
     */
    private function onSale(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }
        $plans = Plan::active()
            ->whereHas('servers', static fn(Builder $entry) => $entry->whereIn('server_id', $serverIds))
            ->with(['servers' => static fn(Relation $entry) => $entry->whereIn('server_id', $serverIds)])
            ->get()
            ->filter(self::renewsSomething(...));
        $covered = $this->selector->coverage($plans);

        $byServer = [];
        foreach ($serverIds as $serverId) {
            $byServer[$serverId] = $plans
                ->filter(static fn(Plan $plan): bool => $covered[$plan->id] && $plan->servers->contains(static fn($entry): bool => (int) $entry->server_id === $serverId))
                ->values();
        }

        return $byServer;
    }

    /**
     * What the renewal's checkout renews with (preview()) — the service's own plan, the plan named among its choices, or
     * the package named — or why there is none (refusal()), asked in RenewalRefusal's order after the method (its
     * callers' first question): whether its shop sells at all (an agent's whose selling is suspended renews nothing),
     * what is named against the service's own plan, whether it may be renewed now at all, its window, a renewal under
     * way, then what is named among what it may pick. Nothing is priced. The builder and the
     * plans on sale are read once, and only when asked; the plans read come with it (picks()' list), none when unread.
     *
     * @return array{Plan|CustomPrice|RenewalRefusal, Collection<int, Plan>}
     */
    private function renewsWith(Subscription $subscription, int|CustomPrice|null $with): array
    {
        if (!$subscription->shop()->sells()) {
            return [RenewalRefusal::SellingSuspended, new Collection()];
        }
        $own = $this->planFor($subscription);
        if ($own !== null && $with instanceof CustomPrice) {
            return [RenewalRefusal::RenewsOnItsPlan, new Collection()];
        }
        if ($own !== null && $with !== null && $with !== $own->id) {
            return [RenewalRefusal::PlanNotOffered, new Collection()];
        }
        $builds = $own === null && $this->buildable($subscription);
        // The plans on sale are not read for a package the builder takes: nothing else is asked of them then.
        $choices = $own === null && !($builds && $with instanceof CustomPrice) ? $this->choices($subscription) : new Collection();

        return [match (true) {
            $own === null && !$builds && $choices->isEmpty() => RenewalRefusal::NotRenewable,
            !$this->window($subscription)->open => RenewalRefusal::NotYet,
            $this->orders->renewalUnderWay($subscription) => RenewalRefusal::UnderWay,
            $own !== null => $own,
            $with instanceof CustomPrice => $builds ? $with : RenewalRefusal::BuilderOff,
            $with === null => $builds ? RenewalRefusal::PickAPlanOrPackage : RenewalRefusal::PickAPlan,
            default => $choices->firstWhere('id', $with) ?? RenewalRefusal::PlanNotOffered,
        }, $choices];
    }

    /** A plan that gives a renewal something: a term or traffic. */
    private static function renewsSomething(Plan $plan): bool
    {
        return $plan->duration_hours > 0 || $plan->trafficBytes() > 0;
    }
}
