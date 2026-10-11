<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Discounts\DTO\DiscountLine;
use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Subscriptions\DTO\ExtraOffer;
use App\Modules\Subscriptions\DTO\RenewalPreview;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\ServiceTerms;
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
     * `$autoRenewDays` before its end), `$renewable`, `$extras` (each kind null when none may be bought), `$linkRotation`.
     * `$client` is what its panel was just asked, when it was (POST …/refresh): whether it is connected.
     *
     * @param array{traffic: ExtraOffer|null, time: ExtraOffer|null} $extras
     * @return array<string, mixed>
     */
    public static function present(Subscription $subscription, bool $autoRenewOffered, int $autoRenewDays, bool $renewable, array $extras, bool $linkRotation, ?ClientInfo $client = null): array
    {
        $plan = $subscription->plan;
        $server = $subscription->server;

        return [
            'id' => $subscription->id,
            'name' => $subscription->remote_name,
            'status' => $subscription->status->value,
            'plan' => $plan === null ? null : ['id' => $plan->id, 'name' => $plan->name],
            'server' => ['id' => $server->id, 'name' => $server->name],
            // The panel no longer has the client: the link it handed out works no more.
            'link' => $subscription->status === SubscriptionStatus::Deleted ? null : $subscription->subscription_url,
            'traffic' => self::traffic($subscription),
            'term' => self::term($subscription),
            'auto_renew' => ['on' => $subscription->auto_renew, 'offered' => $autoRenewOffered, 'days_before' => $autoRenewDays],
            'renewable' => $renewable,
            'extras' => ['traffic' => $extras['traffic'] !== null, 'time' => $extras['time'] !== null],
            'link_rotation' => $linkRotation,
            'next_period' => self::nextPeriod($subscription),
            'presence' => $client === null ? null : [
                'online' => $client->online,
                'last_online_at' => $client->lastOnlineAt === null ? null : Carbon::instance($client->lastOnlineAt)->utc()->toIso8601String(),
            ],
            'synced_at' => $subscription->last_synced_at?->toIso8601String(),
            'created_at' => $subscription->created_at->toIso8601String(),
        ];
    }

    /**
     * A renewal before it is paid: the plan, what it costs the customer today (their price less their largest automatic
     * discount, each discount a line), and the service as the renewal would leave it.
     *
     * @return array<string, mixed>
     */
    public static function renewal(RenewalPreview $preview): array
    {
        $plan = $preview->plan;

        return [
            'plan' => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => $plan->price,
                'traffic_gb' => $plan->traffic_gb,
                'duration_days' => ServiceTerms::daysOf($plan->duration_hours),
                'duration_hours' => $plan->duration_hours,
                'checkout_note' => $plan->checkout_note,
            ],
            'price' => $preview->price,
            'discounts' => array_map(static fn(DiscountLine $line): array => $line->present(), $preview->quote->lines()),
            'after' => [
                'term' => self::term($preview->after),
                'traffic' => self::traffic($preview->after),
                'next_period' => self::nextPeriod($preview->after),
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
     * A renewal queued behind the period in use: when that period ends, and the most traffic left then; null for none.
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
     * What of each extra (GB of traffic, days of time) may be bought now: the unit price, the amounts offered with their
     * prices, and the least and most that may be typed while any amount may; null for a kind none of which may be bought.
     *
     * @param array{traffic: ExtraOffer|null, time: ExtraOffer|null} $offers
     * @return array{traffic: array{price: string|null, options: list<array{quantity: int, price: string}>, custom: array{min: int, max: int}|null}|null, time: array{price: string|null, options: list<array{quantity: int, price: string}>, custom: array{min: int, max: int}|null}|null}
     */
    public static function extras(array $offers): array
    {
        return ['traffic' => self::offer($offers['traffic']), 'time' => self::offer($offers['time'])];
    }

    /** @return array{price: string|null, options: list<array{quantity: int, price: string}>, custom: array{min: int, max: int}|null}|null */
    private static function offer(?ExtraOffer $offer): ?array
    {
        return $offer === null ? null : [
            'price' => $offer->price,
            'options' => $offer->choices(),
            'custom' => $offer->typed() ? ['min' => $offer->min, 'max' => $offer->max] : null,
        ];
    }
}
