<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Orders\Models\Order;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The removal of the shop's services that are done with, by its two rules: its ended services' (ExpiredServicesSettings
 * — so many hours after one ended, off by default) and its free tests' (TestAccountSettings — so many hours after a test
 * ended, so many days after one never connected). The hours count from when a service ended (`ended_at`, or a deadline
 * passed before a sync said so). A service switched off by support is never removed, nor one about which an order is
 * open (Order::OPEN — a renewal waiting for its receipt, a delivery support must retry).
 *
 * removesAt() says when a service goes, for every screen; due() lists the current shop's whose time came, and
 * stillDue() judges one again as its panel has it now (Tasks\CleanupServicesTask, through ProvisioningService::deleteIf()).
 */
final class ServiceCleanup
{
    /** The most of each rule's services one run takes up in a shop: the rest wait for the next. */
    private const BATCH = 100;

    public function __construct(
        private readonly ExpiredServicesSettings $expired,
        private readonly TestAccountSettings $tests,
    ) {}

    /** When the shop removes the service by itself, by its rules now; null when it does not (it runs, or no rule applies). */
    public function removesAt(Subscription $subscription): ?Carbon
    {
        $now = Carbon::now();
        if ($subscription->is_test) {
            if (!$this->tests->cleansUp()) {
                return null;
            }
            $ended = self::endedAt($subscription, $now);
            if ($ended !== null) {
                return $ended->copy()->addHours($this->tests->cleanupHours());
            }
            $days = $this->tests->unusedDays();

            return $days > 0 && $subscription->isActive() && $subscription->awaitsFirstUse() ? $subscription->created_at->copy()->addDays($days) : null;
        }

        return $this->expired->enabled() ? self::endedAt($subscription, $now)?->copy()->addHours($this->expired->hours()) : null;
    }

    /**
     * The current shop's services whose time to go came at `$now`, by its rules: the ended tests and ended services past
     * their hours, and the tests never connected past their days — none with an open order. At most BATCH of each.
     *
     * @return Collection<int, Subscription>
     */
    public function due(Carbon $now): Collection
    {
        $due = new Collection();
        if ($this->tests->cleansUp()) {
            $due = $due->merge(self::ended(Subscription::query()->where('is_test', true), $now, $this->tests->cleanupHours())->get());
            $days = $this->tests->unusedDays();
            if ($days > 0) {
                $due = $due->merge(self::batch(Subscription::active()->where('is_test', true)->whereNull('expires_at')->where('duration_hours', '>', 0)
                    ->where('created_at', '<=', $now->copy()->subDays($days)))->get());
            }
        }
        if ($this->expired->enabled()) {
            $due = $due->merge(self::ended(Subscription::query()->where('is_test', false), $now, $this->expired->hours())->get());
        }

        return $due->load('server');
    }

    /** Whether the service's time to go has come at `$now` as it is now (read afresh from its panel), with no order open. */
    public function stillDue(Subscription $subscription, Carbon $now): bool
    {
        $at = $this->removesAt($subscription);

        return $at !== null && $at->lte($now) && !self::openOrders()->where('subscription_id', $subscription->id)->exists();
    }

    /**
     * A batch() of the services over at `$now` for `$hours` at least, by endedAt() asked of the database.
     *
     * @param Builder<Subscription> $services
     * @return Builder<Subscription>
     */
    private static function ended(Builder $services, Carbon $now, int $hours): Builder
    {
        return self::batch(Subscription::over($services, $now)
            ->whereRaw('COALESCE(ended_at, CASE WHEN expires_at <= ? THEN expires_at END, updated_at) <= ?', [$now, $now->copy()->subHours($hours)]));
    }

    /**
     * At most BATCH of `$services`, none with an order open, the lowest id first.
     *
     * @param Builder<Subscription> $services
     * @return Builder<Subscription>
     */
    private static function batch(Builder $services): Builder
    {
        return $services->whereNotIn('id', self::openOrders()->select('subscription_id'))->orderBy('id')->limit(self::BATCH);
    }

    /** @return Builder<Order> The orders about a service that are not finished with. */
    private static function openOrders(): Builder
    {
        return Order::query()->whereNotNull('subscription_id')->whereIn('status', Order::OPEN);
    }

    /**
     * When it ended for the count (ended() asks the database the same): `ended_at`, else a deadline passed that no sync
     * has caught up with yet, else its row's last change; null while it is not over.
     */
    private static function endedAt(Subscription $subscription, Carbon $now): ?Carbon
    {
        if (!$subscription->isOver($now)) {
            return null;
        }
        $deadline = $subscription->expires_at !== null && $subscription->expires_at->lte($now) ? $subscription->expires_at : null;

        return $subscription->ended_at ?? $deadline ?? $subscription->updated_at;
    }
}
