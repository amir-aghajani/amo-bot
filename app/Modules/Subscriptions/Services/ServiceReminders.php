<?php

declare(strict_types=1);

namespace App\Modules\Subscriptions\Services;

use App\Core\Database\Transitions;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Telegram\Api\Pacer;
use App\Modules\Users\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * «یادآوری»: tells the customer in the bot that a service ends soon — within the admin's days of its deadline — or that
 * its traffic runs low — the admin's share of its quota used (ReminderSettings). Each once while the service stays past
 * its threshold: a mark on the row (`expiry_reminded_at`, `traffic_reminded_at`) is claimed before the reminder goes and
 * cleared once the service is back under the threshold — renewed, given more traffic, its counters reset, or no longer
 * running — so the next time it gets near, it is reminded again. The rows are read as the last sync left them
 * (Tasks\SyncSubscriptionsTask). A service whose «تمدید خودکار» is on hears about its deadline from the renewal instead
 * (renewed, or the wallet short); a banned customer hears nothing.
 */
final class ServiceReminders
{
    /** At most this many reminders a run; the rest go on the next — paced as every bulk send of the bot's (Pacer). */
    private const BATCH = 100;

    public function __construct(
        private readonly ReminderSettings $settings,
        private readonly AutoRenewal $autoRenewal,
        private readonly CustomerNotifier $notifier,
        private readonly Pacer $pacer,
    ) {}

    /** Send the reminders that are due, as the admin set them; answers how many went out. */
    public function send(): int
    {
        $sent = $this->settings->expiryEnabled() ? $this->expiry($this->settings->expiryDays(), self::BATCH) : 0;
        if ($this->settings->trafficEnabled()) {
            $sent += $this->traffic($this->settings->trafficPercent(), self::BATCH - $sent);
        }

        return $sent;
    }

    /** Services ending within `$days` days. */
    private function expiry(int $days, int $limit): int
    {
        $window = now()->addDays($days);
        self::rearm('expiry_reminded_at', static fn(Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $window));

        $walletOn = $this->autoRenewal->walletOn();
        $sent = 0;
        foreach (self::due(Subscription::expiringWithin($days), 'expiry_reminded_at')->lazyById(self::BATCH) as $subscription) {
            if ($sent >= $limit) {
                break;
            }
            $renewable = $walletOn && AutoRenewal::renewable($subscription);
            // «تمدید خودکار» speaks for itself: it renews the service, or tells them the wallet is short.
            if ($renewable && $subscription->auto_renew) {
                continue;
            }
            if (Transitions::claim($subscription, 'expiry_reminded_at')) {
                $this->notifier->expiryReminder($subscription, autoRenewHint: $renewable);
                $sent++;
                $this->pacer->pace();
            }
        }

        return $sent;
    }

    /** Services that used `$percent` percent of their quota or more. */
    private function traffic(int $percent, int $limit): int
    {
        self::rearm('traffic_reminded_at', static fn(Builder $q) => $q->where('traffic_limit_bytes', 0)->orWhereRaw('(upload_bytes + download_bytes) * 100 < traffic_limit_bytes * ?', [$percent]));

        $due = Subscription::active()->where('traffic_limit_bytes', '>', 0)->whereRaw('(upload_bytes + download_bytes) * 100 >= traffic_limit_bytes * ?', [$percent]);
        $sent = 0;
        foreach (self::due($due, 'traffic_reminded_at')->limit($limit)->get() as $subscription) {
            if (Transitions::claim($subscription, 'traffic_reminded_at')) {
                $this->notifier->trafficReminder($subscription);
                $sent++;
                $this->pacer->pace();
            }
        }

        return $sent;
    }

    /**
     * Clear the mark of every service back under its threshold (`$under`) or no longer running: it is reminded again the
     * next time it gets near.
     *
     * @param \Closure(Builder<Subscription>): mixed $under
     */
    private static function rearm(string $mark, \Closure $under): void
    {
        Subscription::query()
            ->whereNotNull($mark)
            ->where(static fn(Builder $q) => $q->where('status', '!=', SubscriptionStatus::Active->value)->orWhere($under))
            ->update([$mark => null]);
    }

    /**
     * Those of them not reminded yet, whose customer the bot still serves.
     *
     * @param Builder<Subscription> $query
     * @return Builder<Subscription>
     */
    private static function due(Builder $query, string $mark): Builder
    {
        return $query->whereNull($mark)
            ->whereHas('user', static fn(Builder $user) => $user->where('status', UserStatus::Active->value))
            ->with(['user', 'plan', 'server'])
            ->oldest('id');
    }
}
