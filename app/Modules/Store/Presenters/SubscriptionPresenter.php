<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Catalog\DTO\CustomPrice;
use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Orders\DTO\Quote;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\DTO\RenewalWindow;
use App\Modules\Subscriptions\Enums\ExtraKind;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTerms;
use App\Modules\Subscriptions\Services\SubscriptionDirectory;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * A customer's service as their website shows it — as the shop last saw it on its panel (`synced_at`) — with what they
 * may do with it, its renewal's preview, and the extras it may buy. What it may do is judged by the caller for a whole
 * page at once (Store\Services\CustomerSubscriptions); its plan and server come loaded.
 */
final class SubscriptionPresenter
{
    /**
     * The service. The flags say what the customer may do now: `$autoRenewOffered` (its switch is offered, renewing
     * `$autoRenewDays` before its end), `$renewable` (on its own plan: CustomerRenewal::plansFor()), `$picksPlan` (one
     * without a plan, on a plan the customer picks or a package they build: CustomerRenewal::pickable()),
     * `$renewalWindow` (whether either may be renewed yet: CustomerRenewal::window()), `$extras` (each kind null when
     * none may be bought), `$linkRotation`. `$removesAt` is when the shop removes it by itself
     * (ServiceCleanup::removesAt()). `$client` is what its panel was just asked, when it was (POST …/refresh): whether
     * it is connected.
     *
     * @param array{traffic: ExtraOffer|null, time: ExtraOffer|null, devices: ExtraOffer|null} $extras
     * @return array<string, mixed>
     */
    public static function present(Subscription $subscription, bool $autoRenewOffered, int $autoRenewDays, bool $renewable, bool $picksPlan, RenewalWindow $renewalWindow, array $extras, bool $linkRotation, ?Carbon $removesAt, ?ClientInfo $client = null): array
    {
        $plan = $subscription->plan;
        $server = $subscription->server;

        return [
            'id' => $subscription->id,
            'name' => $subscription->remote_name,
            'status' => $subscription->status->value,
            'is_test' => $subscription->is_test,
            'claimed_at' => $subscription->claimed_at?->toIso8601String(),
            'plan' => $plan === null ? null : ['id' => $plan->id, 'name' => $plan->name],
            'server' => ['id' => $server->id, 'name' => $server->name],
            // The panel no longer has the client: the link it handed out works no more.
            'link' => $subscription->status === SubscriptionStatus::Deleted ? null : $subscription->subscription_url,
            'traffic' => self::traffic($subscription),
            'term' => self::term($subscription),
            'ended_at' => $subscription->ended_at?->toIso8601String(),
            'removes_at' => $removesAt?->toIso8601String(),
            'auto_renew' => ['on' => $subscription->auto_renew, 'offered' => $autoRenewOffered, 'days_before' => $autoRenewDays],
            'renewable' => $renewable,
            'picks_plan' => $picksPlan,
            'renewal_window' => [
                'open' => $renewalWindow->open,
                'opens_at' => $renewalWindow->opensAt?->toIso8601String(),
                'days_before' => $renewalWindow->daysBefore,
                'traffic_percent' => $renewalWindow->percent,
            ],
            // How many devices may use it at once (0: no limit), what extra devices raise.
            'devices' => $subscription->ip_limit,
            'extras' => ['traffic' => $extras['traffic'] !== null, 'time' => $extras['time'] !== null, 'devices' => $extras['devices'] !== null],
            'link_rotation' => $linkRotation,
            'next_period' => self::nextPeriod($subscription),
            'next_devices' => SubscriptionDirectory::nextDevices($subscription),
            'presence' => $client === null ? null : [
                'online' => $client->online,
                'last_online_at' => $client->lastOnlineAt === null ? null : Carbon::instance($client->lastOnlineAt)->utc()->toIso8601String(),
            ],
            'synced_at' => $subscription->last_synced_at?->toIso8601String(),
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }

    /**
     * A renewal before it is paid: the plan — or the package the customer built, with its two prices and the devices it
     * gives —, what it costs the customer today (their price less their largest automatic discount, each discount a
     * line), the method it is by among those the shop offers, and the service as the renewal would leave it by that
     * method.
     *
     * @return array<string, mixed>
     */
    public static function renewal(RenewalPreview $preview): array
    {
        $with = $preview->with;

        return [
            'method' => $preview->method->value,
            'methods' => array_map(static fn(RenewalMethod $method): string => $method->value, $preview->methods),
            'plan' => $with instanceof CustomPrice ? null : PlanPresenter::terms($with),
            'custom' => $with instanceof CustomPrice ? [
                'gb' => $with->gb,
                'days' => $with->days,
                'devices' => $preview->package->ipLimit,
                'traffic_price' => $with->traffic,
                'days_price' => $with->time,
            ] : null,
            'price' => $preview->quote->amount(),
            'discounts' => DiscountLine::presentAll($preview->quote->lines()),
            'after' => [
                'term' => self::term($preview->after),
                'traffic' => self::traffic($preview->after),
                'next_period' => self::nextPeriod($preview->after),
                'next_devices' => SubscriptionDirectory::nextDevices($preview->after),
            ],
        ];
    }

    /**
     * Its quota and what of it is used and left, in bytes (`remaining_bytes` null for unlimited).
     *
     * @return array{limit_bytes: int, used_bytes: int, remaining_bytes: int|null}
     */
    private static function traffic(Subscription $subscription): array
    {
        return [
            'limit_bytes' => $subscription->traffic_limit_bytes,
            'used_bytes' => $subscription->usedBytes(),
            'remaining_bytes' => $subscription->remainingBytes(),
        ];
    }

    /**
     * Its term: how long, when its clock started and when it ends — or that it waits for the first connection.
     *
     * @return array{duration_days: int, duration_hours: int, starts_at: string|null, expires_at: string|null, awaits_first_use: bool}
     */
    private static function term(Subscription $subscription): array
    {
        return [
            'duration_days' => ServiceTerms::daysOf($subscription->duration_hours),
            'duration_hours' => $subscription->duration_hours,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'awaits_first_use' => $subscription->awaitsFirstUse(),
        ];
    }

    /**
     * A renewal's traffic queued behind the period in use: when that period ends, and the most traffic left then; null
     * for none (a device limit may still wait for it: SubscriptionDirectory::nextDevices()).
     *
     * @return array{ends_at: string, bytes: int}|null
     */
    private static function nextPeriod(Subscription $subscription): ?array
    {
        return $subscription->period_ends_at === null || $subscription->next_period_bytes === null ? null : [
            'ends_at' => $subscription->period_ends_at->toIso8601String(),
            'bytes' => $subscription->next_period_bytes,
        ];
    }

    /**
     * What of each extra (GB of traffic, days of time, devices) may be bought now: the unit price, the amounts offered
     * with their prices, the least and most that may be typed while any amount may, and the days devices are charged
     * for (their prices prorated to them); null for a kind none of which may be bought. Each price is the shop's
     * (`price`) beside what the customer's order is charged for it (`offer_price`: `$priced`, less their automatic
     * discount), with that `discount`.
     *
     * @param array{traffic: ExtraOffer|null, time: ExtraOffer|null, devices: ExtraOffer|null} $offers
     * @param \Closure(ExtraKind, string): Quote $priced What a price of the kind comes to for the customer
     * @return array{traffic: array<string, mixed>|null, time: array<string, mixed>|null, devices: array<string, mixed>|null}
     */
    public static function extras(array $offers, \Closure $priced): array
    {
        return [
            'traffic' => self::offer($offers['traffic'], ExtraKind::Traffic, $priced),
            'time' => self::offer($offers['time'], ExtraKind::Time, $priced),
            'devices' => self::offer($offers['devices'], ExtraKind::Devices, $priced),
        ];
    }

    /**
     * One kind's offer, each price with what it comes to. Its `discount` is the one its largest price takes — every
     * price's, but where rounding to whole Toman leaves two discounts taking as much off a small one.
     *
     * @param \Closure(ExtraKind, string): Quote $priced
     * @return array{price: string|null, offer_price: string|null, discount: array{source: string, label: string, percent: int|null, ends_at: string|null}|null, options: list<array{quantity: int, price: string, offer_price: string}>, custom: array{min: int, max: int}|null, days: int|null}|null
     */
    private static function offer(?ExtraOffer $offer, ExtraKind $kind, \Closure $priced): ?array
    {
        if ($offer === null) {
            return null;
        }
        $price = $offer->unitPrice();
        $unit = $price === null ? null : $priced($kind, $price);
        $largest = $unit;
        $options = [];
        foreach ($offer->choices() as $choice) {
            $quote = $priced($kind, $choice['price']);
            $options[] = $choice + ['offer_price' => $quote->amount()];
            $largest = $largest === null || Money::compare($quote->base, $largest->base) > 0 ? $quote : $largest;
        }

        return [
            'price' => $price,
            'offer_price' => $unit?->amount(),
            'discount' => PlanPresenter::discount($largest?->automatic),
            'options' => $options,
            'custom' => $offer->typed() ? ['min' => $offer->min, 'max' => $offer->max] : null,
            'days' => $offer->days,
        ];
    }
}
