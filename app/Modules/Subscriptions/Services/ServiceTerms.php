<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * The new quota and term a service gets from a renewal, a grant, an extra bought, or the start of a queued period — and
 * keeps when a refund takes a renewal or an extra back.
 * Pure arithmetic on the row as the panel last reported it — no panel, no database — so every rule lives and is tested
 * here; ProvisioningService sends the result to the panel and records what the panel answers.
 *
 * A term is counted in hours everywhere (`duration_hours`: an hourly plan's hours, a daily plan's days × 24); what is
 * given in days (a grant, an extension, extra time) is turned into hours here.
 */
final class ServiceTerms
{
    /** An hour and a day, in the seconds a panel counts a term in. */
    public const HOUR = 3600;
    public const DAY = 86400;

    public const HOURS_A_DAY = 24;

    public function __construct(
        /** The quota: 0 = unlimited. */
        public readonly int $totalBytes,
        public readonly Expiry $expiry,
        /** The term in hours, counted from the first connection; 0 = never ends. */
        public readonly int $durationHours,
        public readonly int $ipLimit,
        /** Reset the counters before the new quota is sent. */
        public readonly bool $resetCounters = false,
        /** A renewal queued behind the period in use: when that period ends, and the most traffic it leaves then. */
        public readonly ?Carbon $periodEndsAt = null,
        public readonly ?int $nextPeriodBytes = null,
    ) {}

    /** A term of `$hours` the panel counts from the first connection; never ends without one. */
    public static function termOf(int $hours): Expiry
    {
        return $hours > 0 ? Expiry::afterFirstUse($hours * self::HOUR) : Expiry::never();
    }

    /**
     * A term of `$hours` in whole days, rounded up — the APIs' `duration_days` beside `duration_hours`. Zero only for no
     * term, so a six-hour term never reads as one that never ends.
     */
    public static function daysOf(int $hours): int
    {
        return $hours > 0 ? intdiv($hours + self::HOURS_A_DAY - 1, self::HOURS_A_DAY) : 0;
    }

    /** The service's term as the row knows it: its deadline, the term still waiting for the first connection, or none. */
    public static function expiryOf(Subscription $subscription): Expiry
    {
        return $subscription->expires_at !== null ? Expiry::at($subscription->expires_at->toDateTimeImmutable()) : self::termOf($subscription->duration_hours);
    }

    /**
     * When the service's clock started, given its current deadline: null without a deadline; otherwise the row's start,
     * else the deadline minus the term, else `$now`. Used for the panel's answer and a renewal's preview alike.
     */
    public static function startedAt(Subscription $subscription, ?Carbon $deadline, Carbon $now): ?Carbon
    {
        if ($deadline === null) {
            return null;
        }

        return $subscription->starts_at ?? ($subscription->duration_hours > 0 ? $deadline->copy()->subHours($subscription->duration_hours) : $now);
    }

    /**
     * A renewal on `$plan`. The time left always carries: a running service gets the plan's term on top of its deadline,
     * one whose clock has not started gets a longer term (still from the first connection), an ended one a new term from
     * its next connection; a plan without a term makes it never end. The traffic follows the admin's rule
     * (`$carryTraffic`):
     * - a service whose period has not passed gets the plan's traffic on top of its quota at once. When traffic is not
     *   carried, the period's end and the plan's traffic are queued (nextPeriod() caps what remains then); a renewal
     *   queued behind a pending one adds to it;
     * - an ended service starts a fresh count: the plan's traffic, plus what was left when traffic is carried;
     * - an unlimited plan makes the service unlimited; an unlimited service renewed on a limited plan starts afresh.
     */
    public static function renewal(Subscription $subscription, Plan $plan, bool $carryTraffic): self
    {
        $hours = $plan->duration_hours;
        $deadline = $subscription->expires_at;
        $running = $deadline !== null && $deadline->isFuture();
        [$expiry, $term] = match (true) {
            $hours <= 0 => [Expiry::never(), 0],
            $running => [self::later($deadline, $hours), $subscription->duration_hours + $hours],
            $subscription->awaitsFirstUse() => [self::termOf($subscription->duration_hours + $hours), $subscription->duration_hours + $hours],
            default => [self::termOf($hours), $hours],
        };

        $planBytes = $plan->trafficBytes();
        $limit = $subscription->traffic_limit_bytes;
        $left = $subscription->remainingBytes() ?? 0;
        $periodOver = $deadline !== null && !$running;
        [$total, $fresh] = match (true) {
            $planBytes === 0 => [0, $periodOver],
            $limit === 0 => [$planBytes, true],
            $periodOver => [($carryTraffic ? $left : 0) + $planBytes, true],
            default => [max($limit, $subscription->usedBytes()) + $planBytes, false],
        };
        [$periodEnds, $nextBytes] = match (true) {
            $total === 0 || $fresh => [null, null],
            $subscription->period_ends_at !== null => [$subscription->period_ends_at, ($subscription->next_period_bytes ?? 0) + $planBytes],
            !$carryTraffic && $running && $left > 0 => [$deadline, $planBytes],
            default => [null, null],
        };

        return new self($total, $expiry, $term, $plan->ip_limit, $fresh, $periodEnds, $nextBytes);
    }

    /**
     * The service as these terms would leave it, active: a copy of its row, nothing saved and no panel asked — what a
     * renewal's checkout shows before it is paid. The delivery reads the panel first, so the preview is only as fresh as
     * the row's last copy of the panel's numbers.
     */
    public function preview(Subscription $subscription): Subscription
    {
        $deadline = $this->expiry->deadline();
        $expiresAt = $deadline !== null ? Carbon::instance($deadline) : null;

        return $subscription->replicateQuietly()->forceFill([
            'status' => SubscriptionStatus::Active,
            'traffic_limit_bytes' => $this->totalBytes,
            'duration_hours' => $this->durationHours,
            'expires_at' => $expiresAt,
            // An ended service's new term waits for its next connection: no start yet, as the panel will say.
            'starts_at' => self::startedAt($subscription, $expiresAt, now()),
            'period_ends_at' => $this->periodEndsAt,
            'next_period_bytes' => $this->nextPeriodBytes,
        ] + ($this->resetCounters ? ['upload_bytes' => 0, 'download_bytes' => 0] : []));
    }

    /**
     * What these terms decide on the row itself, beside what the panel answers: the term and the queued period. (A
     * renewal writes the plan's IP limit too; nothing else changes it.)
     *
     * @return array{duration_hours: int, period_ends_at: Carbon|null, next_period_bytes: int|null}
     */
    public function columns(): array
    {
        return [
            'duration_hours' => $this->durationHours,
            'period_ends_at' => $this->periodEndsAt,
            'next_period_bytes' => $this->nextPeriodBytes,
        ];
    }

    /**
     * Days and traffic on top of what a running service has — its share of a grant, or support's extension. The days
     * move its deadline, or lengthen a term still waiting for the first connection; the traffic goes on top of
     * max(quota, used). A service without a term takes no days, an unlimited one no traffic. A queued renewal period
     * moves with the days, and keeps the traffic given.
     */
    public static function grant(Subscription $subscription, int $days, int $bytes): self
    {
        $hours = $subscription->hasTerm() ? $days * self::HOURS_A_DAY : 0;
        $deadline = $subscription->expires_at;
        $expiry = match (true) {
            $hours === 0 => self::expiryOf($subscription),
            $deadline !== null => self::later($deadline, $hours),
            default => self::termOf($subscription->duration_hours + $hours),
        };
        $limit = $subscription->traffic_limit_bytes;
        $total = $limit === 0 || $bytes === 0 ? $limit : max($limit, $subscription->usedBytes()) + $bytes;
        $next = $subscription->next_period_bytes;

        return new self(
            totalBytes: $total,
            expiry: $expiry,
            durationHours: $subscription->duration_hours + $hours,
            ipLimit: $subscription->ip_limit,
            periodEndsAt: $subscription->period_ends_at?->copy()->addHours($hours),
            nextPeriodBytes: $next !== null && $total > 0 ? $next + $bytes : $next,
        );
    }

    /**
     * Extra traffic or time the customer bought: what a grant gives, except that days for a service whose time ran out
     * start a new term from its next connection (as an ended service's renewal does) — added to a deadline already
     * passed, they would leave it ended.
     */
    public static function extra(Subscription $subscription, int $days, int $bytes): self
    {
        if ($days > 0 && $subscription->isExpiredByTime()) {
            $hours = $days * self::HOURS_A_DAY;

            return new self($subscription->traffic_limit_bytes, self::termOf($hours), $hours, $subscription->ip_limit);
        }

        return self::grant($subscription, $days, $bytes);
    }

    /**
     * What a refund takes back of a renewal or an extra: `$hours` off the service's term and `$bytes` off its quota, as
     * far as the service still has them. A deadline moved back before now ends the service now, and so does a term still
     * waiting for the first connection with nothing left of it; the quota never goes below 1 byte (0 would be unlimited),
     * so a service that used more than it keeps ends by traffic. A service that never ends, or is unlimited, keeps that
     * part. A queued renewal period loses the traffic taken (cleared when none is left of it); an extra's days move it
     * back as they had moved it forward, a renewal's leave it where it was.
     */
    public static function takeBack(Subscription $subscription, int $hours, int $bytes, bool $renewal): self
    {
        $now = Carbon::now();
        $hours = $subscription->hasTerm() ? $hours : 0;
        $back = $subscription->expires_at?->copy()->subHours($hours);
        $left = $subscription->duration_hours - $hours;
        $expiry = match (true) {
            $hours === 0 => self::expiryOf($subscription),
            $back !== null => Expiry::at(($back->lt($now) ? $now : $back)->toDateTimeImmutable()),
            $left > 0 => self::termOf($left),
            default => Expiry::at($now->toDateTimeImmutable()),
        };

        $limit = $subscription->traffic_limit_bytes;
        $total = $limit === 0 || $bytes === 0 ? $limit : max(1, $limit - $bytes);
        $next = $subscription->next_period_bytes;
        [$periodEnds, $nextBytes] = match (true) {
            $next === null => [null, null],
            $next - $bytes <= 0 => [null, null],
            default => [$renewal ? $subscription->period_ends_at : $subscription->period_ends_at?->copy()->subHours($hours), $next - $bytes],
        };

        return new self($total, $expiry, $hours === 0 ? $subscription->duration_hours : max(1, $left), $subscription->ip_limit, periodEndsAt: $periodEnds, nextPeriodBytes: $nextBytes);
    }

    /**
     * The period a renewal was queued behind has ended and the shop does not carry unused traffic: from now on at most
     * the renewed traffic (`next_period_bytes`) remains, counted afresh — less when the customer already dipped into it.
     * Null when there is nothing to cap: the service is no longer active, or used everything.
     */
    public static function nextPeriod(Subscription $subscription): ?self
    {
        $left = min($subscription->remainingBytes() ?? 0, $subscription->next_period_bytes ?? 0);
        if (!$subscription->isActive() || $left <= 0) {
            return null;
        }

        return new self($left, self::expiryOf($subscription), $subscription->duration_hours, $subscription->ip_limit, resetCounters: true);
    }

    /** A fixed deadline `$hours` after `$deadline`. */
    private static function later(Carbon $deadline, int $hours): Expiry
    {
        return Expiry::at($deadline->copy()->addHours($hours)->toDateTimeImmutable());
    }
}
