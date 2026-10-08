<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * What a service is given when it is renewed, granted days and traffic, or starts the period a renewal was queued
 * behind — numbers only, decided from the row as the panel last reported it: no panel, no database, so every rule is
 * read (and tested) in one place. ProvisioningService sends them to the panel and keeps what the panel answers.
 */
final class ServiceTerms
{
    /** A day of a term, in the seconds a panel counts one in. */
    public const DAY = 86400;

    public function __construct(
        /** The quota: 0 = unlimited. */
        public readonly int $totalBytes,
        public readonly Expiry $expiry,
        /** The term in days, counted from the first connection; 0 = never ends. */
        public readonly int $durationDays,
        public readonly int $ipLimit,
        /** The counters start afresh. */
        public readonly bool $resetCounters = false,
        /** A renewal queued behind the period in use: when that period ends, and the most it leaves then. */
        public readonly ?Carbon $periodEndsAt = null,
        public readonly ?int $nextPeriodBytes = null,
    ) {}

    /** A term the panel counts from the first connection; never, without one. */
    public static function termOf(int $days): Expiry
    {
        return $days > 0 ? Expiry::afterFirstUse($days * self::DAY) : Expiry::never();
    }

    /** The service's term as the row knows it: its deadline, the term still waiting for the first connection, or none. */
    public static function expiryOf(Subscription $subscription): Expiry
    {
        return $subscription->expires_at !== null ? Expiry::at($subscription->expires_at->toDateTimeImmutable()) : self::termOf($subscription->duration_days);
    }

    /**
     * When the service's clock started, by the deadline it has now: none without one — a term still waiting for the first
     * connection, or no term —; with one, the row's start, else counted back from the deadline by its term, else `$now`.
     * The one reading of a start: the panel's answer's (ProvisioningService) and a renewal's preview's alike.
     */
    public static function startedAt(Subscription $subscription, ?Carbon $deadline, Carbon $now): ?Carbon
    {
        if ($deadline === null) {
            return null;
        }

        return $subscription->starts_at ?? ($subscription->duration_days > 0 ? $deadline->copy()->subDays($subscription->duration_days) : $now);
    }

    /**
     * A renewal on `$plan`. The days left always carry: a running service gets the plan's days on top of its deadline,
     * one whose clock has not started a longer term (still from the first connection), an ended one a new term from its
     * next connection; a plan without a term makes it never end. The traffic, by the admin's rule (`$carryTraffic`):
     * - a service whose period has not passed gets the plan's traffic on top of its quota — nothing it has left is taken
     *   away now. Carried, that is all; not carried, what the period leaves unused goes when it ends: the period's end
     *   and the plan's traffic are queued (nextPeriod() caps what remains then), a renewal queued behind a pending one
     *   adding to it;
     * - an ended one starts a fresh count: the plan's traffic, plus what was left when carried;
     * - an unlimited plan makes it unlimited; an unlimited service renewed on a limited plan starts a fresh count.
     */
    public static function renewal(Subscription $subscription, Plan $plan, bool $carryTraffic): self
    {
        $days = $plan->duration_days;
        $deadline = $subscription->expires_at;
        $running = $deadline !== null && $deadline->isFuture();
        [$expiry, $term] = match (true) {
            $days <= 0 => [Expiry::never(), 0],
            $running => [Expiry::at($deadline->copy()->addDays($days)->toDateTimeImmutable()), $subscription->duration_days + $days],
            $subscription->awaitsFirstUse() => [self::termOf($subscription->duration_days + $days), $subscription->duration_days + $days],
            default => [self::termOf($days), $days],
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
     * The service as these terms leave it, active: a copy of its row — nothing saved, no panel asked —, what a renewal's
     * checkout shows before it is paid. The delivery reads the panel first, so the preview is as good as the row's last
     * copy of the panel's numbers.
     */
    public function preview(Subscription $subscription): Subscription
    {
        $deadline = $this->expiry->deadline();
        $expiresAt = $deadline !== null ? Carbon::instance($deadline) : null;

        return $subscription->replicateQuietly()->forceFill([
            'status' => SubscriptionStatus::Active,
            'traffic_limit_bytes' => $this->totalBytes,
            'duration_days' => $this->durationDays,
            'expires_at' => $expiresAt,
            // An ended service's new term waits for its next connection: no start, as the panel's answer will say.
            'starts_at' => self::startedAt($subscription, $expiresAt, now()),
            'period_ends_at' => $this->periodEndsAt,
            'next_period_bytes' => $this->nextPeriodBytes,
        ] + ($this->resetCounters ? ['upload_bytes' => 0, 'download_bytes' => 0] : []));
    }

    /**
     * Days and traffic on top of what a running service has — its share of a grant. The days move its deadline, or
     * lengthen a term still waiting for the first connection; the traffic goes on top of its quota, counted from what
     * it used when that went past the quota. A service without a term takes no days and an unlimited one no traffic. A
     * renewal queued behind the period in use moves with the days, and the traffic given outlives that period.
     */
    public static function grant(Subscription $subscription, int $days, int $bytes): self
    {
        $days = $subscription->hasTerm() ? $days : 0;
        $deadline = $subscription->expires_at;
        $expiry = match (true) {
            $days === 0 => self::expiryOf($subscription),
            $deadline !== null => Expiry::at($deadline->copy()->addDays($days)->toDateTimeImmutable()),
            default => self::termOf($subscription->duration_days + $days),
        };
        $limit = $subscription->traffic_limit_bytes;
        $total = $limit === 0 || $bytes === 0 ? $limit : max($limit, $subscription->usedBytes()) + $bytes;
        $next = $subscription->next_period_bytes;

        return new self(
            totalBytes: $total,
            expiry: $expiry,
            durationDays: $subscription->duration_days + $days,
            ipLimit: $subscription->ip_limit,
            periodEndsAt: $subscription->period_ends_at?->copy()->addDays($days),
            nextPeriodBytes: $next !== null && $total > 0 ? $next + $bytes : $next,
        );
    }

    /**
     * The period a renewal was queued behind has ended, and the shop does not carry what a period leaves unused: from now
     * on at most the renewed traffic remains (`next_period_bytes`), counted afresh — less when the customer already
     * dipped into it. Null when there is nothing to cap: a service no longer active, or one that used everything.
     */
    public static function nextPeriod(Subscription $subscription): ?self
    {
        $left = min($subscription->remainingBytes() ?? 0, $subscription->next_period_bytes ?? 0);
        if (!$subscription->isActive() || $left <= 0) {
            return null;
        }

        return new self($left, self::expiryOf($subscription), $subscription->duration_days, $subscription->ip_limit, resetCounters: true);
    }
}
