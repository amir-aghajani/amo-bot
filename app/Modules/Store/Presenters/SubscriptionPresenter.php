<?php

declare(strict_types=1);

namespace App\Modules\Store\Presenters;

use App\Modules\Providers\DTO\ClientInfo;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * One of a customer's services as their website shows it — as the shop last saw it on its panel (`synced_at`), with
 * what the customer may do with it now. Its plan and server are loaded with it; what it may do is the caller's to judge
 * (Store\Services\CustomerSubscriptions, with the services the bot asks the same of).
 */
final class SubscriptionPresenter
{
    /**
     * `$autoRenewOffered`: its «تمدید خودکار» switch is offered (AutoRenewal::offeredFor()), and `$autoRenewDays` how many
     * days before its end it renews (RenewalSettings::autoRenewDays()); `$renewable`: its customer may renew it now
     * (CustomerRenewal::planFor()); `$linkRotation`: its link may be changed now (ProvisioningService::rotatable());
     * `$client`: what its panel was just asked — whether it is connected — when it was, and had it.
     *
     * @return array<string, mixed>
     */
    public static function present(Subscription $subscription, bool $autoRenewOffered, int $autoRenewDays, bool $renewable, bool $linkRotation, ?ClientInfo $client = null): array
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
     * Its quota and what of it is used and left, in bytes — of the service, or of a renewal's preview of it.
     *
     * @return array{limit_bytes: int, used_bytes: int, remaining_bytes: int|null}
     */
    public static function traffic(Subscription $subscription): array
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
     * @return array{duration_days: int, starts_at: string|null, expires_at: string|null, awaits_first_use: bool}
     */
    public static function term(Subscription $subscription): array
    {
        return [
            'duration_days' => $subscription->duration_days,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'expires_at' => $subscription->expires_at?->toIso8601String(),
            'awaits_first_use' => $subscription->awaitsFirstUse(),
        ];
    }

    /**
     * A renewal queued behind the period in use: when that period ends, and the most that is left then; null for none.
     *
     * @return array{ends_at: string, bytes: int}|null
     */
    public static function nextPeriod(Subscription $subscription): ?array
    {
        return $subscription->period_ends_at === null || $subscription->next_period_bytes === null ? null : [
            'ends_at' => $subscription->period_ends_at->toIso8601String(),
            'bytes' => $subscription->next_period_bytes,
        ];
    }
}
