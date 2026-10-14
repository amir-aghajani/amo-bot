<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Bots\Exceptions\SellingSuspendedException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Models\Order;
use App\Modules\Providers\ProviderRegistry;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\ExtraPrices;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * «حجم، زمان و کاربر اضافه»: extra traffic, time or devices a customer buys for one of their services, in the bot or on
 * the website, at the service plan's own prices or else the shop's (ExtrasSettings), while the shop sells them. Paid
 * through the checkout (OrderService::openExtra()) and delivered by ProvisioningService::addExtra(), which asks the
 * same rule (refusal()) again of the panel's numbers.
 *
 * Which services: running or ended — not switched off by support (it stays off until support switches it on), nor gone
 * from its panel. Traffic needs a quota and time left (a service whose traffic ran out runs again). Time needs a term and
 * traffic left (a service whose time ran out starts a new term at its next connection). One ended on both counts needs
 * a renewal. Devices need a device limit (unlimited takes none), time and traffic left — they last as long as the period
 * in use, which a renewal of a service ended on either count replaces at once —, and a panel that holds a client to its
 * IP limit (Capabilities::$ipLimit); their prices are for 30 days, charged for the days left of the period in use
 * (deviceDays()). In an agent's shop, traffic is held to what the agent's pool still has, since the delivery draws it;
 * time and devices draw nothing — and none is sold while its selling is suspended (Bot::sells()), though one
 * paid before is still delivered.
 */
final class CustomerExtras
{
    public const NOT_RUNNING = 'این سرویس فعال نیست؛ با پشتیبانی در تماس باشید.';
    public const UNLIMITED = 'حجم این سرویس نامحدود است.';
    public const NO_TERM = 'زمان این سرویس نامحدود است.';
    public const TIME_OVER = 'زمان این سرویس تمام شده است؛ اول زمانش را بخرید یا سرویس را تمدید کنید.';
    public const TRAFFIC_OVER = 'حجم این سرویس تمام شده است؛ اول حجمش را بخرید یا سرویس را تمدید کنید.';
    /** A free test account takes nothing: it is removed once it ends. */
    public const TEST = 'اکانت تست حجم، زمان یا کاربر اضافه نمی‌گیرد؛ برای ادامه یک پلن بخرید.';
    public const UNLIMITED_DEVICES = 'تعداد کاربر این سرویس نامحدود است.';
    /** The service's panel does not hold a client to its IP limit (Capabilities::$ipLimit). */
    public const NO_DEVICE_LIMIT = 'سرور این سرویس تعداد کاربر را محدود نمی‌کند؛ کاربر اضافه برایش فروخته نمی‌شود.';

    /** The shop sells none of the kind for the service now: its prices offer none, or an agent's pool covers none. */
    public const NOT_SOLD = 'این فروشگاه برای این سرویس %s نمی‌فروشد.';

    /** The states a service may take an extra in. */
    private const STATES = [SubscriptionStatus::Active, SubscriptionStatus::Expired];

    public function __construct(
        private readonly ExtrasSettings $settings,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * What the customer may buy of `$kind` for the service now (traffic held to an agent's pool, devices prorated to
     * what is left of the period in use), while the service may take it (refusal()); null for nothing.
     */
    public function offer(Subscription $subscription, ExtraKind $kind): ?ExtraOffer
    {
        return $this->offersFor([$subscription])[$subscription->id][$kind->value];
    }

    /**
     * offer() for a page of services, by id, reading the shop's prices and an agent's pool once.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, array{traffic: ExtraOffer|null, time: ExtraOffer|null, devices: ExtraOffer|null}>
     */
    public function offersFor(iterable $subscriptions): array
    {
        $enabled = $this->settings->enabled();
        $shopPrices = null;
        $pools = [];
        $offers = [];
        foreach ($subscriptions as $subscription) {
            $offers[$subscription->id] = ['traffic' => null, 'time' => null, 'devices' => null];
            // An agent's shop whose selling is suspended sells no extra (its delivery of one paid before asks refusal()).
            if (!$enabled || !$subscription->shop()->sells()) {
                continue;
            }

            $plan = $subscription->plan;
            $prices = ($plan === null ? null : ExtrasSettings::planPrices($plan)) ?? $shopPrices ??= $this->settings->prices();
            foreach (ExtraKind::cases() as $kind) {
                $offer = $this->held($subscription, $kind, $prices, $pools);
                $offers[$subscription->id][$kind->value] = $offer->sells() && $this->refusal($subscription, $kind) === null ? $offer : null;
            }
        }

        return $offers;
    }

    /**
     * Why the service cannot take `$kind` now, worded for the customer; null when it can. The same rule serves the
     * checkout and the delivery (ProvisioningService::addExtra(), by the panel's numbers then).
     */
    public function refusal(Subscription $subscription, ExtraKind $kind): ?string
    {
        if (!in_array($subscription->status, self::STATES, true)) {
            return self::NOT_RUNNING;
        }
        if ($subscription->is_test) {
            return self::TEST;
        }

        return match ($kind) {
            ExtraKind::Traffic => match (true) {
                $subscription->traffic_limit_bytes === 0 => self::UNLIMITED,
                $subscription->isExpiredByTime() => self::TIME_OVER,
                default => null,
            },
            ExtraKind::Time => match (true) {
                !$subscription->hasTerm() => self::NO_TERM,
                $subscription->isExpiredByTraffic() => self::TRAFFIC_OVER,
                default => null,
            },
            ExtraKind::Devices => match (true) {
                $subscription->ip_limit === 0 => self::UNLIMITED_DEVICES,
                !$this->providers->capabilities($subscription->server->driver)->ipLimit => self::NO_DEVICE_LIMIT,
                $subscription->isExpiredByTime() => self::TIME_OVER,
                $subscription->isExpiredByTraffic() => self::TRAFFIC_OVER,
                default => null,
            },
        };
    }

    /**
     * The days extra devices are charged for, of the 30 their prices are set for — the days they last: what is left of
     * the period in use (whole days, a part of one counting as one, at least 1) — until a renewal queued behind it
     * begins (`period_ends_at`), else until its deadline —, its whole term while it waits for its first connection, or
     * the full 30 for one that never ends.
     */
    public static function deviceDays(Subscription $subscription): int
    {
        $queued = $subscription->period_ends_at;
        $end = $queued !== null && $queued->isFuture() ? $queued : $subscription->expires_at;

        return match (true) {
            $end !== null => max(1, intdiv(max(0, $end->getTimestamp() - Carbon::now()->getTimestamp()) + ServiceTerms::DAY - 1, ServiceTerms::DAY)),
            $subscription->duration_hours > 0 => ServiceTerms::daysOf($subscription->duration_hours),
            default => ExtraOffer::PERIOD_DAYS,
        };
    }

    /**
     * The extra devices bought for the term of a service still waiting for its first connection — delivered since its
     * device limit last went back to a package's (`ip_limit_reset_at`), and not taken back —, which a renewal keeps on
     * top of its package's (ServiceTerms::renewal()): they were charged for all of that term, none of which has run. Its
     * delivery and its checkout's preview ask the same. 0 for any other service, without a query.
     */
    public static function devicesBought(Subscription $subscription): int
    {
        if (!$subscription->awaitsFirstUse()) {
            return 0;
        }

        return (int) Order::query()
            ->where('subscription_id', $subscription->id)
            ->where('type', OrderType::ExtraDevices->value)
            ->where('status', OrderStatus::Fulfilled->value)
            ->whereNull('taken_back_at')
            ->when($subscription->ip_limit_reset_at !== null, static fn(Builder $query) => $query->where('fulfilled_at', '>', $subscription->ip_limit_reset_at))
            ->sum('devices');
    }

    /**
     * What `$quantity` of `$kind` costs for the service, as offered now (offer()).
     *
     * @throws ValidationException 422 on `status` while the service cannot take it (refusal()) or its shop sells nothing
     *                             (an agent's whose selling is suspended); on the kind's field (`gb`, `days`, `devices`)
     *                             while the shop sells none of it for the service, or not that much
     */
    public function quote(Subscription $subscription, ExtraKind $kind, int $quantity): string
    {
        if (!$subscription->shop()->sells()) {
            throw SellingSuspendedException::onStatus();
        }
        $problem = $this->refusal($subscription, $kind);
        if ($problem !== null) {
            throw ValidationException::on('status', $problem);
        }

        $offer = $this->offer($subscription, $kind) ?? throw ValidationException::on($kind->field(), sprintf(self::NOT_SOLD, $kind->label()));

        return $offer->priceOf($quantity) ?? throw ValidationException::on($kind->field(), self::notOffered($kind, $offer));
    }

    /**
     * The offer at the service's prices: devices prorated to what is left of the period in use (deviceDays()), traffic
     * held to the agent's pool (read once per shop into `$pools`, by bot id).
     *
     * @param array<int, int> $pools
     */
    private function held(Subscription $subscription, ExtraKind $kind, ExtraPrices $prices, array &$pools): ExtraOffer
    {
        $offer = $prices->of($kind);
        if ($kind === ExtraKind::Devices) {
            return $offer->prorated(self::deviceDays($subscription));
        }
        $shop = $subscription->shop();
        if ($kind !== ExtraKind::Traffic || $shop->isMain()) {
            return $offer;
        }

        return $offer->upTo($pools[$shop->id] ??= intdiv(max(0, $shop->trafficBalance()), Traffic::GIGABYTE));
    }

    /** The refusal of an amount not on offer, listing what may be bought instead. */
    private static function notOffered(ExtraKind $kind, ExtraOffer $offer): string
    {
        $listed = implode('، ', array_map(static fn(array $choice): string => $kind->words($choice['quantity']) . ' (' . Money::format($choice['price']) . ')', $offer->choices()));

        return match (true) {
            $offer->typed() && $listed !== '' => sprintf('این مقدار فروخته نمی‌شود؛ %s بین %s تا %s %s بنویسید، یا یکی از این‌ها را بخرید: %s.', $kind->label(), Persian::number($offer->min), Persian::number($offer->max), $kind->unit(), $listed),
            $offer->typed() => sprintf('این مقدار فروخته نمی‌شود؛ %s بین %s تا %s %s بنویسید.', $kind->label(), Persian::number($offer->min), Persian::number($offer->max), $kind->unit()),
            default => sprintf('این مقدار فروخته نمی‌شود؛ یکی از این‌ها را بخرید: %s.', $listed),
        };
    }
}
