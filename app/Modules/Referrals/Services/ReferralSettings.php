<?php

declare(strict_types=1);

namespace App\Modules\Referrals\Services;

use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\Form;
use App\Modules\Referrals\Enums\StartGiftTrigger;
use App\Modules\Rewards\Forms\PrizePlan;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The referral program's settings (the `referral` group, off by default): the commission rates (renewals their own, 0:
 * none) and the first-payment-only rule, the start gift, the plan reward, and the fake-referral alert thresholds (0
 * switches an alert off).
 */
final class ReferralSettings implements DeclaresSettings
{
    /** The largest start gift, either side's, in Toman. */
    public const START_GIFT_MAX = '10000000';

    private readonly Toggle $enabled;
    private readonly Number $rate;
    private readonly Number $renewalRate;
    private readonly Toggle $firstOnly;
    private readonly Amount $startReferrer;
    private readonly Amount $startInvitee;
    private readonly Choice $startWhen;
    private readonly Number $rewardEvery;
    private readonly PrizePlan $rewardPlan;
    private readonly Number $alertBurst;
    private readonly Number $alertMinutes;
    private readonly Number $alertIdle;

    public function __construct(private readonly Settings $settings)
    {
        $this->enabled = new Toggle('referral_enabled', 'referral.enabled', default: false);
        $this->rate = new Number('referral_rate', 'referral.rate', default: 10, label: 'درصد پورسانت', min: 1, max: 100);
        $this->renewalRate = new Number('referral_renewal_rate', 'referral.renewal_rate', default: 10, label: 'درصد پورسانت تمدید', min: 0, max: 100);
        $this->firstOnly = new Toggle('referral_first_only', 'referral.first_only', default: false);
        $this->startReferrer = new Amount('referral_start_referrer', 'referral.start_referrer', '0', label: 'هدیه شروع معرف', min: '0', max: self::START_GIFT_MAX);
        $this->startInvitee = new Amount('referral_start_invitee', 'referral.start_invitee', '0', label: 'هدیه شروع زیرمجموعه', min: '0', max: self::START_GIFT_MAX);
        $this->startWhen = new Choice('referral_start_when', 'referral.start_when', StartGiftTrigger::Purchase->value, array_map(static fn(StartGiftTrigger $when): string => $when->value, StartGiftTrigger::cases()), 'زمان هدیه شروع را انتخاب کنید.');
        $this->rewardEvery = new Number('referral_reward_every', 'referral.reward_every', default: 0, label: 'تعداد خرید برای جایزه', min: 0, max: 1000);
        $this->rewardPlan = new PrizePlan('referral_reward_plan_id', 'referral.reward_plan_id', neededWhen: $this->rewardEvery);
        $this->alertBurst = new Number('referral_alert_burst', 'referral.alert_burst', default: 10, label: 'تعداد عضویت در بازه کوتاه', min: 0, max: 10000);
        $this->alertMinutes = new Number('referral_alert_minutes', 'referral.alert_minutes', default: 60, label: 'بازه هشدار', min: 1, max: 10080, unit: 'دقیقه');
        $this->alertIdle = new Number('referral_alert_idle', 'referral.alert_idle', default: 20, label: 'تعداد زیرمجموعه بدون خرید', min: 0, max: 100000);
    }

    public function groups(): array
    {
        return [new Form('referral', [
            $this->enabled,
            $this->rate,
            $this->renewalRate,
            $this->firstOnly,
            $this->startReferrer,
            $this->startInvitee,
            $this->startWhen,
            $this->rewardEvery,
            $this->rewardPlan,
            $this->alertBurst,
            $this->alertMinutes,
            $this->alertIdle,
        ])];
    }

    /** Whether links bring referrals and payments earn commissions. */
    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** The share of a referred customer's payment, in percent, credited to the referrer. */
    public function rate(): int
    {
        return $this->settings->read($this->rate);
    }

    /** The share of a referred customer's renewal, in percent, credited to the referrer in place of rate(); 0: none. */
    public function renewalRate(): int
    {
        return $this->settings->read($this->renewalRate);
    }

    /** Whether only the first payment of each referred customer earns a commission. */
    public function firstOnly(): bool
    {
        return $this->settings->read($this->firstOnly);
    }

    /** The start gift to the referrer's wallet, Toman — "0.00" for none. */
    public function startReferrer(): string
    {
        return $this->settings->read($this->startReferrer);
    }

    /** The start gift to the newcomer's wallet, Toman — "0.00" for none. */
    public function startInvitee(): string
    {
        return $this->settings->read($this->startInvitee);
    }

    /** When the start gift is paid: as the newcomer joins, or as their first purchase is paid. */
    public function startWhen(): StartGiftTrigger
    {
        return StartGiftTrigger::from($this->settings->read($this->startWhen));
    }

    /** Each time this many more of a referrer's referrals bought, the referrer is given the reward plan; 0: never. */
    public function rewardEvery(): int
    {
        return $this->settings->read($this->rewardEvery);
    }

    /** The plan a referrer is given free (its number), or null for none. */
    public function rewardPlanId(): ?int
    {
        return $this->settings->read($this->rewardPlan);
    }

    /** How many joining through one link within alertMinutes() raise an alert; 0: never. */
    public function alertBurst(): int
    {
        return $this->settings->read($this->alertBurst);
    }

    /** The alerts' window, in minutes: the burst's, and once an alert of a kind was raised, none of it again within it. */
    public function alertMinutes(): int
    {
        return $this->settings->read($this->alertMinutes);
    }

    /** How many referrals of one referrer, none of whom bought, raise an alert (and each as many again); 0: never. */
    public function alertIdle(): int
    {
        return $this->settings->read($this->alertIdle);
    }
}
