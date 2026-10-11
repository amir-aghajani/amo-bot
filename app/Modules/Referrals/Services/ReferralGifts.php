<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Notifications\Services\CustomerNotifier;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Payments\Models\Payment;
use App\Modules\Referrals\Enums\StartGiftTrigger;
use App\Modules\Referrals\Models\ReferralBonus;
use App\Modules\Referrals\Models\ReferralReward;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Exceptions\RewardUnavailableException;
use App\Modules\Rewards\Services\Rewards;
use App\Modules\Users\Models\User;
use App\Support\Money;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * What a referral brings beyond the commission, given through Rewards:
 * - the start gift («هدیه شروع»): Toman to both referrer and newcomer, when the newcomer joins or makes their first
 *   purchase with a price (the admin's choice). Decided once per newcomer: the ReferralBonus row's unique invitee
 *   settles a race.
 * - the plan reward («جایزه دعوت»): each time the referrer's referrals who bought something with a price reach another
 *   multiple of the admin's number, the referrer gets the plan's service free. One ReferralReward row per milestone is
 *   claimed before giving; only the milestone reached now is given, so lowering the number never pays out several.
 * "Bought" here is Order::paidPurchases(): a free plan must not unlock these.
 * While an alert on the referrer stands (ReferralAlerts), both are withheld and recorded as decided, so clearing the
 * alert never pays them later.
 */
final class ReferralGifts
{
    /** The referrer's wallet line, with the newcomer's number. */
    public const LINE_REFERRER = 'هدیه شروع زیرمجموعه‌گیری (کاربر #%d)';

    /** The newcomer's wallet line. */
    public const LINE_INVITEE = 'هدیه شروع (عضویت با لینک دعوت)';

    /** What the plan reward is given for, in the log. */
    public const REWARD_REASON = 'جایزه دعوت';

    public function __construct(
        private readonly ReferralSettings $settings,
        private readonly ReferralAlerts $alerts,
        private readonly Rewards $rewards,
        private readonly CustomerNotifier $notifier,
        private readonly ConnectionInterface $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * A newcomer joined through the referrer's link (their registration committed): checks for fake referrals first — so
     * the join that raises an alert brings no gift —, then gives the start gift when it is paid on joining. Never throws.
     */
    public function joined(User $newcomer, User $referrer): void
    {
        try {
            $this->alerts->check($referrer, $newcomer);
            if ($this->settings->enabled() && $this->settings->startWhen() === StartGiftTrigger::Join) {
                $this->startGift($newcomer, $referrer, StartGiftTrigger::Join);
            }
        } catch (\Throwable $e) {
            $this->logger->error('The arrival of user {invitee} through a referral link failed: {message}', ['invitee' => $newcomer->id, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * A payment settled (called once, by the call that won it): when it paid a referred customer's first purchase with a
     * price (Order::paidPurchases(), paid any way) — a free plan neither counts nor uses the gift up — gives the start
     * gift (when paid on purchase) and the plan reward.
     */
    public function purchased(Payment $payment): void
    {
        $order = $payment->order;
        if (!$this->settings->enabled() || !$payment->isPaid() || !Order::paidPurchases()->whereKey($order->id)->exists()) {
            return;
        }
        $customer = $order->user;
        $referrer = $customer->referrer;
        if ($referrer === null || $referrer->isBanned() || OrderService::boughtBefore($order, withPrice: true)) {
            return;
        }

        if ($this->settings->startWhen() === StartGiftTrigger::Purchase) {
            $this->startGift($customer, $referrer, StartGiftTrigger::Purchase);
        }
        $this->planReward($referrer);
    }

    /** Decides the newcomer's start gift once; pays and announces it unless an alert withholds it. */
    private function startGift(User $invitee, User $referrer, StartGiftTrigger $trigger): void
    {
        $forReferrer = $this->settings->startReferrer();
        $forInvitee = $this->settings->startInvitee();
        if (!Money::isPositive($forReferrer) && !Money::isPositive($forInvitee)) {
            return;
        }
        $withheld = $this->alerts->flagged($referrer);

        try {
            $this->db->transaction(function () use ($invitee, $referrer, $trigger, $forReferrer, $forInvitee, $withheld): void {
                ReferralBonus::query()->create([
                    'invitee_id' => $invitee->id,
                    'referrer_id' => $referrer->id,
                    'referrer_amount' => $withheld ? '0' : $forReferrer,
                    'invitee_amount' => $withheld ? '0' : $forInvitee,
                    'trigger' => $trigger,
                ]);
                if ($withheld) {
                    return;
                }
                if (Money::isPositive($forReferrer)) {
                    $this->rewards->give($referrer, Reward::wallet($forReferrer), sprintf(self::LINE_REFERRER, $invitee->id));
                }
                if (Money::isPositive($forInvitee)) {
                    $this->rewards->give($invitee, Reward::wallet($forInvitee), self::LINE_INVITEE);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return; // the newcomer's start gift was decided already
        }
        if ($withheld) {
            $this->logger->info('The start gift of user {invitee} withheld: an alert on their referrer {referrer} stands', ['invitee' => $invitee->id, 'referrer' => $referrer->id]);

            return;
        }

        if (Money::isPositive($forReferrer)) {
            $this->notifier->referralStartGift($referrer, $forReferrer, $invitee);
        }
        if (Money::isPositive($forInvitee)) {
            $this->notifier->referralStartGift($invitee, $forInvitee, null);
        }
    }

    /** Gives the referrer's plan reward when their referrals who bought reached a milestone not given yet. */
    private function planReward(User $referrer): void
    {
        $every = $this->settings->rewardEvery();
        $planId = $this->settings->rewardPlanId();
        if ($every === 0 || $planId === null) {
            return;
        }
        $plan = Plan::query()->find($planId);
        if ($plan === null) {
            $this->logger->warning('The referral reward plan {plan} is gone: no reward given', ['plan' => $planId]);

            return;
        }

        $buyers = Order::paidPurchases()->whereIn('user_id', $referrer->referrals()->select('id'))->distinct()->count('user_id');
        $milestone = intdiv($buyers, $every) * $every;
        if ($milestone === 0) {
            return;
        }

        try {
            // Claimed before anything is given: of two first purchases settled at once, one gives it.
            $reward = ReferralReward::query()->create(['referrer_id' => $referrer->id, 'milestone' => $milestone]);
        } catch (UniqueConstraintViolationException) {
            return;
        }
        if ($this->alerts->flagged($referrer)) {
            $this->logger->info('The referral reward of user {referrer} at {milestone} withheld: an alert on them stands', ['referrer' => $referrer->id, 'milestone' => $milestone]);

            return;
        }

        try {
            $given = $this->rewards->give($referrer, Reward::plan($plan), self::REWARD_REASON, tell: false);
        } catch (\Throwable $e) {
            // Nothing given: the milestone is left for the next referral's first purchase to try again.
            $reward->delete();
            if (!$e instanceof RewardUnavailableException) {
                throw $e;
            }
            $this->logger->warning('The referral reward of user {referrer} at {milestone} could not be given: {reason}', ['referrer' => $referrer->id, 'milestone' => $milestone, 'reason' => $e->getMessage()]);

            return;
        }

        $order = $given->order ?? throw new \LogicException('A plan given without its order.');
        $reward->update(['order_id' => $order->id]);
        $this->notifier->referralRewarded($referrer, $plan->name, $milestone);
        $this->rewards->sendService($given);
    }
}
