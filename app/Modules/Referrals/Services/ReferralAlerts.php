<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Core\Exceptions\ValidationException;
use App\Modules\Auth\Actor;
use App\Modules\Auth\Exceptions\ActorRefusedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Referrals\Enums\AlertReason;
use App\Modules\Referrals\Models\ReferralAlert;
use App\Modules\Telegram\Reports\ShopReports;
use App\Modules\Users\Models\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Fake-referral alerts («هشدار زیرمجموعه‌گیری فیک»), checked as each referral joins:
 * - burst: the join is exactly the N-th through the link within the window (counting referral ids up to it, so of two
 *   joining at once only one is the N-th);
 * - idle: the join brings the referrals to a multiple of N while none of them ever bought anything with a price.
 * Each reason is raised at most once per window, and reported to the group in the same transaction. While an alert
 * stands, the referrer gets no start gift or plan reward (commissions still count: that money came in). Clearing an
 * alert deletes it: no history is kept.
 */
final class ReferralAlerts
{
    /** An alert another decision cleared first. */
    public const GONE = 'این هشدار پیش‌تر رفع شده است.';

    public function __construct(
        private readonly ReferralSettings $settings,
        private readonly ShopReports $reports,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Raises (and reports) an alert when `$newcomer`'s join makes one due. Under a lock on the referrer's row, so two
     * joins at once are judged one after the other.
     */
    public function check(User $referrer, User $newcomer): void
    {
        $burst = $this->settings->alertBurst();
        $idle = $this->settings->alertIdle();
        if ($burst === 0 && $idle === 0) {
            return;
        }

        $this->db->transaction(function () use ($referrer, $newcomer, $burst, $idle): void {
            User::query()->whereKey($referrer->id)->lockForUpdate()->value('id');
            $minutes = $this->settings->alertMinutes();
            $upTo = User::query()->where('referred_by', $referrer->id)->where('id', '<=', $newcomer->id);

            if ($burst > 0) {
                $recent = (clone $upTo)->where('created_at', '>=', now()->subMinutes($minutes)->utc())->count();
                if ($recent === $burst) {
                    $this->raise($referrer, AlertReason::Burst, $recent, $minutes);
                }
            }
            if ($idle > 0) {
                $joined = (clone $upTo)->count();
                if ($joined >= $idle && $joined % $idle === 0 && !self::anyBought($referrer)) {
                    $this->raise($referrer, AlertReason::Idle, $joined, $minutes);
                }
            }
        });
    }

    /** Whether an alert on the referrer stands: their start gifts and plan rewards are withheld. */
    public function flagged(User $referrer): bool
    {
        return ReferralAlert::query()->where('referrer_id', $referrer->id)->exists();
    }

    /**
     * Support clears the alert (deleted, logged with who did it); the referrer earns gifts again from now on. Nobody
     * clears an alert on themselves.
     *
     * @throws ActorRefusedException 403 the actor is the referrer
     * @throws ValidationException 422 on `status`: cleared meanwhile
     */
    public function clear(ReferralAlert $alert, Actor $actor): void
    {
        if ($actor->is($alert->referrer_id)) {
            throw ActorRefusedException::ownAlert();
        }
        if (ReferralAlert::query()->whereKey($alert->id)->delete() !== 1) {
            throw ValidationException::on('status', self::GONE);
        }

        $this->logger->info('Referral alert {id} on user {referrer} ({reason}) cleared by {actor}', ['id' => $alert->id, 'referrer' => $alert->referrer_id, 'reason' => $alert->reason->value, 'actor' => $actor->label()]);
    }

    /** Keeps and reports the alert, unless one of the same reason was raised on the referrer within the window. */
    private function raise(User $referrer, AlertReason $reason, int $count, int $minutes): void
    {
        $raised = ReferralAlert::query()
            ->where('referrer_id', $referrer->id)
            ->where('reason', $reason->value)
            ->where('created_at', '>=', now()->subMinutes($minutes)->utc())
            ->exists();
        if ($raised) {
            return;
        }

        $alert = ReferralAlert::query()->create(['referrer_id' => $referrer->id, 'reason' => $reason, 'count' => $count]);
        $alert->setRelation('referrer', $referrer);
        $this->reports->referralAlert($alert, $minutes);
        $this->logger->warning('Referral alert on user {referrer}: {reason}, {count}', ['referrer' => $referrer->id, 'reason' => $reason->value, 'count' => $count]);
    }

    /** Whether any of the referrer's referrals ever bought something with a price (a free plan is no purchase here). */
    private static function anyBought(User $referrer): bool
    {
        return Order::paidPurchases()->whereIn('user_id', $referrer->referrals()->select('id'))->exists();
    }
}
