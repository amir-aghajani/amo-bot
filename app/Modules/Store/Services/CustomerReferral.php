<?php

declare(strict_types=1);

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Referrals\Services\ReferralService;
use App\Modules\Referrals\Services\ReferralSettings;
use App\Modules\Users\Models\User;
use App\Support\Money;

/**
 * A customer's part in the referral program as their website shows it, like the bot's «👥 زیرمجموعه‌گیری»: the program's
 * terms, their invite code and bot link, and what their referrals brought them.
 */
final class CustomerReferral
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly ReferralSettings $settings,
    ) {}

    /**
     * The terms (commission rates, and the start gift and plan reward while they are on), the customer's invite code (made
     * the first time it is asked, whether or not the program runs, as the bot does), their bot link, and their referrals'
     * count, commissions and rewards.
     *
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        ['referrals' => $invited, 'earned' => $earned] = $this->referrals->statsFor($user);
        $forReferrer = $this->settings->startReferrer();
        $forInvitee = $this->settings->startInvitee();
        $plan = $this->settings->rewardEvery() > 0 && $this->settings->rewardPlanId() !== null ? Plan::query()->find($this->settings->rewardPlanId()) : null;

        return [
            'enabled' => $this->settings->enabled(),
            'rate' => $this->settings->rate(),
            'renewal_rate' => $this->settings->renewalRate(),
            'first_only' => $this->settings->firstOnly(),
            'start_gift' => Money::isPositive($forReferrer) || Money::isPositive($forInvitee)
                ? ['referrer' => $forReferrer, 'invitee' => $forInvitee, 'trigger' => $this->settings->startWhen()->value]
                : null,
            'reward' => $plan === null ? null : ['every' => $this->settings->rewardEvery(), 'plan' => ['id' => $plan->id, 'name' => $plan->name]],
            'code' => $this->referrals->codeFor($user),
            'bot_link' => $this->referrals->linkFor($user),
            'invited' => $invited,
            'earned' => $earned,
            'rewards' => $this->referrals->rewardsOf($user),
        ];
    }
}
