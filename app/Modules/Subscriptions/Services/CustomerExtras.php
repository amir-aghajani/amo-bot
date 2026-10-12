<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\ExtraPrices;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * «حجم و زمان اضافه»: extra traffic or time a customer buys for one of their services, in the bot or on the website, at
 * the service plan's own prices or else the shop's (ExtrasSettings), while the shop sells them. Paid through the checkout
 * (OrderService::openExtra()) and delivered by ProvisioningService::addExtra(), which asks the same rule (problem()) again
 * of the panel's numbers.
 *
 * Which services: running or ended — not switched off by support (an extra would switch it back on), nor gone from its
 * panel. Traffic needs a quota and time left (a service whose traffic ran out runs again). Time needs a term and traffic
 * left (a service whose time ran out starts a new term at its next connection). One ended on both counts needs a
 * renewal. In an agent's shop, traffic is held to what the agent's pool still has, since the delivery draws it.
 */
final class CustomerExtras
{
    public const NOT_RUNNING = 'این سرویس فعال نیست؛ با پشتیبانی در تماس باشید.';
    public const UNLIMITED = 'حجم این سرویس نامحدود است.';
    public const NO_TERM = 'زمان این سرویس نامحدود است.';
    public const TIME_OVER = 'زمان این سرویس تمام شده است؛ اول زمانش را بخرید یا سرویس را تمدید کنید.';
    public const TRAFFIC_OVER = 'حجم این سرویس تمام شده است؛ اول حجمش را بخرید یا سرویس را تمدید کنید.';
    /** A free test account takes nothing: it is removed once it ends. */
    public const TEST = 'اکانت تست حجم و زمان اضافه نمی‌گیرد؛ برای ادامه یک پلن بخرید.';

    /** The shop sells none of the kind for the service now: its prices offer none, or an agent's pool covers none. */
    public const NOT_SOLD = 'این فروشگاه برای این سرویس %s نمی‌فروشد.';

    /** The states a service may take an extra in. */
    private const STATES = [SubscriptionStatus::Active, SubscriptionStatus::Expired];

    public function __construct(private readonly ExtrasSettings $settings) {}

    /**
     * What the customer may buy of `$kind` for the service now (traffic held to an agent's pool), while the service may
     * take it (problem()); null for nothing.
     */
    public function offer(Subscription $subscription, ExtraKind $kind): ?ExtraOffer
    {
        return $this->offersFor([$subscription])[$subscription->id][$kind->value];
    }

    /**
     * offer() for a page of services, by id, reading the shop's prices and an agent's pool once.
     *
     * @param iterable<Subscription> $subscriptions
     * @return array<int, array{traffic: ExtraOffer|null, time: ExtraOffer|null}>
     */
    public function offersFor(iterable $subscriptions): array
    {
        $enabled = $this->settings->enabled();
        $shopPrices = null;
        $pools = [];
        $offers = [];
        foreach ($subscriptions as $subscription) {
            $offers[$subscription->id] = ['traffic' => null, 'time' => null];
            if (!$enabled) {
                continue;
            }

            $plan = $subscription->plan;
            $prices = ($plan === null ? null : ExtrasSettings::planPrices($plan)) ?? $shopPrices ??= $this->settings->prices();
            foreach (ExtraKind::cases() as $kind) {
                $offer = $this->held($subscription, $kind, $prices, $pools);
                $offers[$subscription->id][$kind->value] = $offer->sells() && self::problem($subscription, $kind) === null ? $offer : null;
            }
        }

        return $offers;
    }

    /**
     * Why the service cannot take `$kind` now, worded for the customer; null when it can. The same rule serves the
     * checkout and the delivery (ProvisioningService::addExtra(), by the panel's numbers then).
     */
    public static function problem(Subscription $subscription, ExtraKind $kind): ?string
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
        };
    }

    /**
     * What `$quantity` of `$kind` costs for the service, as offered now (offer()).
     *
     * @throws ValidationException 422 on `status` while the service cannot take it (problem()); on the kind's field
     *                             (`gb`, `days`) while the shop sells none of it for the service, or not that much
     */
    public function quote(Subscription $subscription, ExtraKind $kind, int $quantity): string
    {
        $problem = self::problem($subscription, $kind);
        if ($problem !== null) {
            throw ValidationException::on('status', $problem);
        }

        $offer = $this->offer($subscription, $kind) ?? throw ValidationException::on($kind->field(), sprintf(self::NOT_SOLD, $kind->label()));

        return $offer->priceOf($quantity) ?? throw ValidationException::on($kind->field(), self::notOffered($kind, $offer));
    }

    /**
     * The offer at the service's prices, traffic held to the agent's pool (read once per shop into `$pools`, by bot id).
     *
     * @param array<int, int> $pools
     */
    private function held(Subscription $subscription, ExtraKind $kind, ExtraPrices $prices, array &$pools): ExtraOffer
    {
        $offer = $prices->of($kind);
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
