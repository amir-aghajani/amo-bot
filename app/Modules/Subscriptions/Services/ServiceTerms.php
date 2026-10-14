<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Modules\Catalog\DTO\Package;
use App\Modules\Providers\DTO\Expiry;
use App\Modules\Subscriptions\Enums\RenewalMethod;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * The new quota, term and device limit a service gets from a renewal, a grant, an extra bought, or the start of a queued
 * period — and keeps when a refund takes a renewal or an extra back.
 * Pure arithmetic on the row as the panel last reported it — no panel, no database — so every rule lives and is tested
 * here; ProvisioningService sends the result to the panel and records what the panel answers.
 *
 * A term is counted in hours everywhere (`duration_hours`: an hourly plan's hours, a daily plan's days × 24); what is
 * given in days (a grant, an extension, extra time) is turned into hours here.
 *
 * Extra devices bought last as long as the period they were bought for: a renewal brings its package's device limit —
 * at once, or once the period in use ends (`next_period_ip_limit`) —, and for the new period they are bought again.
 */
final class ServiceTerms
{
    /** An hour and a day, in the seconds a panel counts a term in. */
    public const HOUR = 3600;
    public const DAY = 86400;

    public const HOURS_A_DAY = Package::HOURS_A_DAY;

    public function __construct(
        /** The quota: 0 = unlimited. */
        public readonly int $totalBytes,
        public readonly Expiry $expiry,
        /** The term in hours, counted from the first connection; 0 = never ends. */
        public readonly int $durationHours,
        /** The device limit now: 0 = unlimited. */
        public readonly int $ipLimit,
        /** Reset the counters before the new quota is sent. */
        public readonly bool $resetCounters = false,
        /**
         * A renewal queued behind the period in use: when that period ends, the most traffic it leaves then (null: none
         * capped), and the device limit from then on (`$nextPeriodIpLimit`; null: as it is).
         */
        public readonly ?Carbon $periodEndsAt = null,
        public readonly ?int $nextPeriodBytes = null,
        /** When the clock starts anew (a renewal that restarts the term of a running service); null leaves it. */
        public readonly ?Carbon $startsAt = null,
        public readonly ?int $nextPeriodIpLimit = null,
        /** When the device limit goes back to a package's, the devices bought before then out of it; null: it does not. */
        public readonly ?Carbon $limitResetAt = null,
        /**
         * What a renewal (or extra days that start a new term) gives, for its refund to take back: the hours and bytes it
         * leaves the service beyond what it had — less than nothing for a reset that replaced more than it gives.
         */
        public readonly int $givenHours = 0,
        public readonly int $givenBytes = 0,
        /**
         * What a renewal starts afresh, for the refunds of the orders before it (given()): its term (nothing of the time
         * left carried, or none at all), its traffic count (nothing of the traffic left carried, or none counted), its
         * device limit (the devices bought before it gone). Extra days for a service whose time ran out start its term
         * afresh too.
         */
        public readonly bool $restartsTerm = false,
        public readonly bool $restartsTraffic = false,
        public readonly bool $restartsIpLimit = false,
        /** A refunded renewal's (limitPutBack()): `limitResetAt` is put back as it was before it, null (never) too. */
        public readonly bool $limitResetBack = false,
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
     * When the service's clock started, given its deadline: null without one; the row's start while its clock ran
     * already — null when that is not known (a client added by its link: its panel does not say) —; else, the clock
     * starting now, the deadline less the term, or `$now` for none. Used for the panel's answer and a renewal's preview
     * alike.
     */
    public static function startedAt(Subscription $subscription, ?Carbon $deadline, Carbon $now): ?Carbon
    {
        return match (true) {
            $deadline === null => null,
            $subscription->expires_at !== null => $subscription->starts_at,
            $subscription->duration_hours > 0 => $deadline->copy()->subHours($subscription->duration_hours),
            default => $now,
        };
    }

    /**
     * A renewal adding `$package` — a plan's, or a service the customer built — by `$method`.
     *
     * The term: by Extend and ResetTraffic the time left carries — a running service gets the package's term on top of
     * its deadline, one whose clock has not started a longer term (still from the first connection), an ended one a new
     * term from its next connection. By ResetTime it starts afresh — from now for a running service (what was left of it
     * is lost), from the first connection for one waiting for it or ended. A package without a term makes it never end.
     *
     * The traffic:
     * - Extend follows the admin's rule (`$carryTraffic`): a service whose period has not passed gets the package's
     *   traffic on top of its quota at once — when traffic is not carried, the period's end and the package's traffic are
     *   queued (nextPeriod() caps what remains then), a renewal queued behind a pending one adding to it —; an ended one
     *   starts a fresh count, the package's traffic plus what was left when traffic is carried;
     * - ResetTraffic: the package's traffic counted afresh (the counters reset, what was left is lost);
     * - ResetTime: the package's traffic on top of what is left, whatever the admin's rule;
     * - an unlimited package makes the service unlimited; an unlimited service renewed on a limited one starts afresh.
     *
     * The devices are the package's (0 = unlimited), whatever the service had — its own limit and the devices bought for
     * its period count no more:
     * - a running service renewed by Extend or ResetTraffic goes on with the period in use, and the devices paid for it
     *   last until it ends: the package's limit is queued behind it (the period queued already, else the deadline), a
     *   renewal queued behind a pending one replacing the limit queued;
     * - one waiting for its first connection takes it at once with the devices bought for it on top (`$devicesBought`):
     *   they were charged for its whole term, none of which has run, and the renewal's term now runs on from it;
     * - any other — ended by time or traffic, renewed by ResetTime (its term starts over), or one that never ends — takes
     *   it at once, the devices bought gone with the period (`limitResetAt`).
     *
     * What it adds, for a refund to take back (`givenHours`, `givenBytes`), is the package's — but by ResetTime the term
     * less what it replaced (the hours left to the deadline, or the term still waiting), and by ResetTraffic on a quota
     * whose period has not passed the package's traffic less what was left: less than nothing when it replaced more, so
     * the refund gives that back. A package without a term or a quota adds none of it: what it replaced is kept whole
     * (given()). What it starts afresh (`restarts…`) — a term or a quota it ends included — is no longer there for an
     * earlier order's refund to take.
     *
     * A queued period whose end has passed has begun, though NextPeriodTask has yet to start it (begun()): the renewal
     * goes on from it — its device limit, its traffic capped —, queued behind the deadline.
     */
    public static function renewal(Subscription $subscription, Package $package, bool $carryTraffic, RenewalMethod $method = RenewalMethod::Extend, int $devicesBought = 0): self
    {
        $row = $subscription;
        $subscription = self::begun($row);
        $hours = $package->hours;
        $deadline = $subscription->expires_at;
        $running = $deadline !== null && $deadline->isFuture();
        $waiting = $subscription->awaitsFirstUse();
        $restart = $method === RenewalMethod::ResetTime;
        $now = Carbon::now();
        [$expiry, $term, $startsAt] = match (true) {
            $hours <= 0 => [Expiry::never(), 0, null],
            $restart && $running => [Expiry::at($now->copy()->addHours($hours)->toDateTimeImmutable()), $hours, $now],
            $restart => [self::termOf($hours), $hours, null],
            $running => [self::later($deadline, $hours), $subscription->duration_hours + $hours, null],
            $waiting => [self::termOf($subscription->duration_hours + $hours), $subscription->duration_hours + $hours, null],
            default => [self::termOf($hours), $hours, null],
        };
        $givenHours = match (true) {
            !$restart || $hours <= 0 => $hours,
            $running => $hours - (int) ceil(($deadline->getTimestamp() - $now->getTimestamp()) / self::HOUR),
            $waiting => $hours - $subscription->duration_hours,
            default => $hours,
        };

        $packageBytes = $package->bytes;
        $limit = $subscription->traffic_limit_bytes;
        $left = $subscription->remainingBytes() ?? 0;
        $periodOver = $deadline !== null && !$running;
        $carried = $restart || $carryTraffic;
        [$total, $fresh] = match (true) {
            $method === RenewalMethod::ResetTraffic => [$packageBytes, true],
            $packageBytes === 0 => [0, $periodOver],
            $limit === 0 => [$packageBytes, true],
            $periodOver => [($carried ? $left : 0) + $packageBytes, true],
            default => [max($limit, $subscription->usedBytes()) + $packageBytes, false],
        };
        $givenBytes = $method === RenewalMethod::ResetTraffic && $packageBytes > 0 && $limit > 0 && !$periodOver ? $packageBytes - $left : $packageBytes;
        // A package without a term or a quota ends the one the service had: kept by given(), whole.
        $restartsTerm = $hours > 0 ? $restart || !($running || $waiting) : $subscription->hasTerm();
        $restartsTraffic = $method === RenewalMethod::ResetTraffic || ($fresh && !($periodOver && $carried)) || ($packageBytes === 0 && $limit > 0);
        // Only Extend queues the package's traffic behind the period in use; the other two settle the traffic now.
        [$periodEnds, $nextBytes] = match (true) {
            $total === 0 || $fresh || $method !== RenewalMethod::Extend => [null, null],
            $subscription->period_ends_at !== null && $subscription->next_period_bytes !== null => [$subscription->period_ends_at, $subscription->next_period_bytes + $packageBytes],
            !$carryTraffic && $running && $left > 0 => [$deadline, $packageBytes],
            default => [null, null],
        };

        $queued = $running && !$restart && !$subscription->isExpiredByTraffic();
        [$ipLimit, $nextIpLimit, $resetAt] = match (true) {
            $queued => [$subscription->ip_limit, $package->ipLimit, null],
            $waiting && !$restart => [$package->ipLimit === 0 ? 0 : $package->ipLimit + $devicesBought, null, null],
            default => [$package->ipLimit, null, $now],
        };
        // The devices wait for the period the traffic waits for, else the one queued already, else the deadline.
        $periodEnds ??= $queued ? $subscription->period_ends_at ?? $deadline : null;
        $restartsIpLimit = $resetAt !== null;
        // A period begun though not started yet went back to its own limit when it began.
        $resetAt ??= $subscription === $row ? null : $subscription->ip_limit_reset_at;

        return new self($total, $expiry, $term, $ipLimit, $fresh, $periodEnds, $nextBytes, $startsAt, $nextIpLimit, $resetAt, $givenHours, $givenBytes, $restartsTerm, $restartsTraffic, $restartsIpLimit);
    }

    /**
     * What a renewal by these terms gives `$subscription` (its row before them), kept on the renewal's order for its
     * refund and the refunds of the orders before it (ProvisioningService::undo()): the hours and traffic it adds (less
     * than nothing for a reset that replaced more); a term it gives a service that never ended, a quota an unlimited one
     * (`gave_term`, `gave_quota`: its refund takes them away again); the term and quota a package without them replaced
     * (`term_was` and `expires_was`: the term in hours and the deadline, null while the term waited for the first
     * connection; `quota_was`: the traffic that was left — its refund puts them back); what it starts afresh
     * (`reset_…`); and the device limit it sets — queued behind the period in use, or at once — with what that replaces:
     * the limit, and for one set at once when the limit had last gone back to a package's (`ip_limit_reset_was`, null:
     * never).
     *
     * @return array{given_hours: int, given_bytes: int, gave_term: bool, gave_quota: bool, term_was: int|null, expires_was: Carbon|null, quota_was: int|null, reset_term: bool, reset_traffic: bool, reset_ip_limit: bool, given_ip_limit: int, ip_limit_was: int|null, ip_limit_queued: bool, ip_limit_reset_was: Carbon|null}
     */
    public function given(Subscription $subscription): array
    {
        $row = self::begun($subscription);
        $queued = $this->nextPeriodIpLimit !== null;
        $endsTerm = $this->durationHours === 0 && $row->hasTerm();
        $endsQuota = $this->totalBytes === 0 && $row->traffic_limit_bytes > 0;

        return [
            'given_hours' => $this->givenHours,
            'given_bytes' => $this->givenBytes,
            'gave_term' => $this->durationHours > 0 && !$row->hasTerm(),
            'gave_quota' => $this->totalBytes > 0 && $row->traffic_limit_bytes === 0,
            'term_was' => $endsTerm ? $row->duration_hours : null,
            'expires_was' => $endsTerm ? $row->expires_at : null,
            'quota_was' => $endsQuota ? $row->remainingBytes() : null,
            'reset_term' => $this->restartsTerm,
            'reset_traffic' => $this->restartsTraffic,
            'reset_ip_limit' => $this->restartsIpLimit,
            'given_ip_limit' => $this->nextPeriodIpLimit ?? $this->ipLimit,
            'ip_limit_was' => $queued ? $row->next_period_ip_limit : $row->ip_limit,
            'ip_limit_queued' => $queued,
            'ip_limit_reset_was' => $queued ? null : $row->ip_limit_reset_at,
        ];
    }

    /**
     * Whether these terms leave the service otherwise than its row has it — its quota, term, device limit, queued period,
     * or when its limit went back to a package's: a refund that finds nothing left to take changes nothing.
     */
    public function changes(Subscription $subscription): bool
    {
        $expiry = self::expiryOf($subscription);

        return $this->totalBytes !== $subscription->traffic_limit_bytes
            || $this->durationHours !== $subscription->duration_hours
            || $this->expiry->deadline()?->getTimestamp() !== $expiry->deadline()?->getTimestamp()
            || $this->expiry->pendingSeconds() !== $expiry->pendingSeconds()
            || $this->ipLimit !== $subscription->ip_limit
            || $this->periodEndsAt?->getTimestamp() !== $subscription->period_ends_at?->getTimestamp()
            || $this->nextPeriodBytes !== $subscription->next_period_bytes
            || $this->nextPeriodIpLimit !== $subscription->next_period_ip_limit
            || ($this->limitResetBack && $this->limitResetAt?->getTimestamp() !== $subscription->ip_limit_reset_at?->getTimestamp());
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
            'starts_at' => $this->startsAt ?? self::startedAt($subscription, $expiresAt, now()),
            'period_ends_at' => $this->periodEndsAt,
            'next_period_bytes' => $this->nextPeriodBytes,
            'next_period_ip_limit' => $this->nextPeriodIpLimit,
            'ip_limit' => $this->ipLimit,
        ] + ($this->resetCounters ? ['upload_bytes' => 0, 'download_bytes' => 0] : []));
    }

    /**
     * What these terms decide on the row itself, beside what the panel answers: the term, the device limit (a panel that
     * keeps none answers none), the queued period, when the clock starts anew, and when the device limit went back to a
     * package's.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'duration_hours' => $this->durationHours,
            'ip_limit' => $this->ipLimit,
            'period_ends_at' => $this->periodEndsAt,
            'next_period_bytes' => $this->nextPeriodBytes,
            'next_period_ip_limit' => $this->nextPeriodIpLimit,
        ] + ($this->startsAt !== null ? ['starts_at' => $this->startsAt] : []) + ($this->limitResetAt !== null || $this->limitResetBack ? ['ip_limit_reset_at' => $this->limitResetAt] : []);
    }

    /**
     * Days and traffic on top of what a running service has — its share of a grant, or support's extension. The days
     * move its deadline, or lengthen a term still waiting for the first connection; the traffic goes on top of
     * max(quota, used). A service without a term takes no days, an unlimited one no traffic. A queued renewal period moves
     * with the days — the period in use runs longer, with its devices —, and keeps the traffic given.
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
            nextPeriodIpLimit: $subscription->next_period_ip_limit,
        );
    }

    /**
     * Extra traffic, time or devices the customer bought: what a grant gives, except that days for a service whose time
     * ran out start a new term from its next connection (as an ended service's renewal does) — added to a deadline
     * already passed, they would leave it ended —, kept on the order as a renewal's restart is (`restartsTerm`,
     * `givenHours`: ProvisioningService::addExtra()), so an earlier order's refund takes nothing of that term. Devices
     * raise its IP limit (an unlimited one stays unlimited) for the period in use: with a renewal queued behind it, the
     * next period keeps the limit it was to have — unless that period's end has passed already (NextPeriodTask has yet
     * to start it): it has begun, and they go onto its limit.
     */
    public static function extra(Subscription $subscription, int $days, int $bytes, int $devices = 0): self
    {
        $ipLimit = $subscription->ip_limit === 0 ? 0 : $subscription->ip_limit + $devices;
        if ($days > 0 && $subscription->isExpiredByTime()) {
            $hours = $days * self::HOURS_A_DAY;

            return new self($subscription->traffic_limit_bytes, self::termOf($hours), $hours, $ipLimit, givenHours: $hours, restartsTerm: true);
        }

        $next = $subscription->next_period_ip_limit;
        $endsAt = $subscription->period_ends_at;
        if ($devices > 0 && $endsAt !== null) {
            $next = match (true) {
                $endsAt->isFuture() => $next ?? $subscription->ip_limit,
                $next === null || $next === 0 => $next,
                default => $next + $devices,
            };
        }

        return self::grant($subscription, $days, $bytes)->limitedTo($ipLimit, $next);
    }

    /**
     * What a refund takes back of a renewal or an extra: `$hours` off the service's term, `$bytes` off its quota and
     * `$devices` — extra devices bought at `$boughtAt` — off its IP limit, as far as the service still has them. A
     * deadline moved back before now ends the service now, and so does a term still waiting for the first connection
     * with nothing left of it; the quota never goes below 1 byte (0 would be unlimited), so a service that used more than
     * it keeps ends by traffic. The IP limit never goes below 1, and the devices are not taken at all once it went back to
     * a package's since they were bought (devicesGone()): they went with their period. A service that never ends, or is
     * unlimited, keeps that part — but a refunded renewal that gave it its term (`$noTerm`) or its quota (`$noQuota`)
     * leaves it never ending, or unlimited, again; one that ended its term or quota comes as a copy of the row with them
     * put back (ProvisioningService::undone()). Less than nothing — what a reset replaced beyond what it gave — is given
     * back: the deadline or the waiting term moves on, the quota grows. A queued renewal period loses the traffic taken
     * (no cap left once none is left of it; traffic given back is the period in use's, the cap stays), and goes once
     * nothing of it is queued; an extra's days move it back as they had moved it forward, a renewal's leave it where it
     * was. A renewal's device limit is put back by limitPutBack().
     */
    public static function takeBack(Subscription $subscription, int $hours, int $bytes, bool $renewal, int $devices = 0, ?Carbon $boughtAt = null, bool $noTerm = false, bool $noQuota = false): self
    {
        $now = Carbon::now();
        $hours = $subscription->hasTerm() ? $hours : 0;
        $back = $subscription->expires_at?->copy()->subHours($hours);
        $left = $subscription->duration_hours - $hours;
        $expiry = match (true) {
            $noTerm => Expiry::never(),
            $hours === 0 => self::expiryOf($subscription),
            $back !== null => Expiry::at(($back->lt($now) ? $now : $back)->toDateTimeImmutable()),
            $left > 0 => self::termOf($left),
            default => Expiry::at($now->toDateTimeImmutable()),
        };
        $term = match (true) {
            $noTerm => 0,
            $hours === 0 => $subscription->duration_hours,
            default => max(1, $left),
        };

        $limit = $subscription->traffic_limit_bytes;
        $total = match (true) {
            $noQuota => 0,
            $limit === 0 || $bytes === 0 => $limit,
            default => max(1, $limit - $bytes),
        };
        $next = $subscription->next_period_bytes;
        $taken = max(0, $bytes);
        // A service that never ends, or is unlimited, has no period to queue, or no traffic to cap.
        $nextBytes = $noTerm || $noQuota || $next === null || $next - $taken <= 0 ? null : $next - $taken;
        $nextIpLimit = $noTerm ? null : $subscription->next_period_ip_limit;
        $periodEnds = match (true) {
            $nextBytes === null && $nextIpLimit === null => null,
            $renewal => $subscription->period_ends_at,
            default => $subscription->period_ends_at?->copy()->subHours($hours),
        };

        $ipLimit = $devices === 0 || self::devicesGone($subscription, $boughtAt) ? $subscription->ip_limit : self::lessDevices($subscription->ip_limit, $devices);

        return new self($total, $expiry, $term, $ipLimit, periodEndsAt: $periodEnds, nextPeriodBytes: $nextBytes, nextPeriodIpLimit: $nextIpLimit);
    }

    /**
     * Whether the extra devices bought at `$boughtAt` are gone from the service's limit: it went back to a package's since
     * (`ip_limit_reset_at`: a renewal, or the period queued beginning), and they with their period.
     */
    public static function devicesGone(Subscription $subscription, ?Carbon $boughtAt): bool
    {
        return $boughtAt !== null && $subscription->ip_limit_reset_at?->gt($boughtAt) === true;
    }

    /** The device limit `$limit` less `$devices`, never below 1; unlimited (0) stays unlimited. */
    public static function lessDevices(int $limit, int $devices): int
    {
        return $limit === 0 || $devices === 0 ? $limit : max(1, $limit - $devices);
    }

    /**
     * These terms of a refunded renewal (takeBack()) with the device limit it gave put back: one it set at once
     * (`$given`, in place of `$was`) undone on the limit the service has now — what changed it since (devices bought or
     * taken back, the panel) stays on top: limitUndone() —, and when it put the limit back to a package's (`$reset`) the
     * moment that had last happened before it back too (`$resetWas`, null: never), so the devices bought before it count
     * again; or — `$queued` — one queued behind the period in use back to what was queued before (`$was`, null for
     * nothing) while that period has not begun and still waits for it (a limit queued since by another stays). The queued
     * period goes once nothing of it is left. Asked only while the limit it set is still the service's
     * (ProvisioningService::undo()).
     */
    public function limitPutBack(int $given, ?int $was, bool $queued, bool $reset = false, ?Carbon $resetWas = null): self
    {
        [$ipLimit, $next] = match (true) {
            $queued => [$this->ipLimit, $this->periodEndsAt !== null && $this->nextPeriodIpLimit === $given ? $was : $this->nextPeriodIpLimit],
            $was === null => [$this->ipLimit, $this->nextPeriodIpLimit],
            default => [self::limitUndone($this->ipLimit, $given, $was), $this->nextPeriodIpLimit],
        };
        $periodEnds = $next === null && $this->nextPeriodBytes === null ? null : $this->periodEndsAt;
        $resetBack = !$queued && $was !== null && $reset;

        return new self($this->totalBytes, $this->expiry, $this->durationHours, $ipLimit, $this->resetCounters, $periodEnds, $this->nextPeriodBytes, $this->startsAt, $next, $resetBack ? $resetWas : $this->limitResetAt, $this->givenHours, $this->givenBytes, limitResetBack: $resetBack);
    }

    /**
     * The device limit `$limit` with a renewal's change of it from `$was` to `$given` undone, and what changed it since
     * kept (0 = unlimited): moved back by the difference, never below 1; unlimited before, unlimited again; one the
     * renewal made unlimited back to `$was`, unless a limit was set since; one made unlimited since stays so. What a
     * refunded renewal leaves, or hands on as what a later one replaced.
     */
    public static function limitUndone(int $limit, int $given, int $was): int
    {
        return match (true) {
            $was === 0 => 0,
            $given === 0 => $limit === 0 ? $was : $limit,
            $limit === 0 => 0,
            default => max(1, $limit + $was - $given),
        };
    }

    /**
     * The period a renewal was queued behind has ended: from now on at most the renewed traffic (`next_period_bytes`)
     * remains, counted afresh — less when the customer already dipped into it — when the shop does not carry unused
     * traffic, and the device limit is the one queued (`next_period_ip_limit`). Null when the panel has nothing to change:
     * the service is no longer active, or nothing is capped (traffic carried, or all of it used) and the limit stays.
     */
    public static function nextPeriod(Subscription $subscription): ?self
    {
        $ipLimit = $subscription->next_period_ip_limit ?? $subscription->ip_limit;
        $left = min($subscription->remainingBytes() ?? 0, $subscription->next_period_bytes ?? 0);
        if (!$subscription->isActive() || ($left <= 0 && $ipLimit === $subscription->ip_limit)) {
            return null;
        }

        return $left > 0
            ? new self($left, self::expiryOf($subscription), $subscription->duration_hours, $ipLimit, resetCounters: true)
            : new self($subscription->traffic_limit_bytes, self::expiryOf($subscription), $subscription->duration_hours, $ipLimit);
    }

    /**
     * The row once the period a renewal was queued behind has ended: nothing queued any more, and the device limit queued
     * in force from the moment the period ended (`ip_limit_reset_at`: the devices bought for it went with it).
     *
     * @return array<string, mixed>
     */
    public static function periodOver(Subscription $subscription): array
    {
        $ipLimit = $subscription->next_period_ip_limit;

        return ['period_ends_at' => null, 'next_period_bytes' => null, 'next_period_ip_limit' => null]
            + ($ipLimit !== null ? ['ip_limit' => $ipLimit, 'ip_limit_reset_at' => $subscription->period_ends_at] : []);
    }

    /**
     * The row with a queued period whose end has passed begun, as NextPeriodTask — yet to run — will begin it
     * (periodOver()): the device limit the one queued, the traffic left capped at what was queued (on the quota, the
     * counters as they are), nothing queued any more. The row itself while no such period waits. A copy: nothing saved.
     */
    private static function begun(Subscription $subscription): Subscription
    {
        $endsAt = $subscription->period_ends_at;
        if ($endsAt === null || $endsAt->isFuture()) {
            return $subscription;
        }
        $cap = $subscription->next_period_bytes;
        $capped = $cap !== null && ($subscription->remainingBytes() ?? 0) > $cap ? ['traffic_limit_bytes' => $subscription->usedBytes() + $cap] : [];

        return (clone $subscription)->forceFill(self::periodOver($subscription) + $capped);
    }

    /** These terms with `$ipLimit` devices now and `$next` from the queued period on. */
    private function limitedTo(int $ipLimit, ?int $next): self
    {
        return new self($this->totalBytes, $this->expiry, $this->durationHours, $ipLimit, $this->resetCounters, $this->periodEndsAt, $this->nextPeriodBytes, $this->startsAt, $next);
    }

    /** A fixed deadline `$hours` after `$deadline`. */
    private static function later(Carbon $deadline, int $hours): Expiry
    {
        return Expiry::at($deadline->copy()->addHours($hours)->toDateTimeImmutable());
    }
}
