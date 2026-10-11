<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Services;

use App\Core\Forms\Fields\Amount;
use App\Core\Forms\Fields\Choice;
use App\Core\Forms\Fields\Number;
use App\Core\Forms\Fields\Toggle;
use App\Core\Forms\FieldSpec;
use App\Core\Forms\Form;
use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\DTO\Reward;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Rewards\Forms\PrizePlan;
use App\Modules\Settings\DeclaresSettings;
use App\Modules\Settings\Services\Settings;

/**
 * The nightly draw's settings (the `lottery` group, off by default): the hour of the shop's day, how many winners, the
 * prize (each kind's amount is its own field, so switching kinds loses nothing typed), one entry per paid order or per
 * buyer, and whether agents are left out.
 */
final class LotterySettings implements DeclaresSettings
{
    /** An entry a paid order; a buyer once whatever they bought. */
    public const PER_ORDER = 'order';
    public const PER_BUYER = 'buyer';

    /** The most Toman a prize may be. */
    public const WALLET_MAX = '100000000';

    private readonly Toggle $enabled;
    private readonly Number $hour;
    private readonly Number $winners;
    private readonly Choice $prize;
    private readonly Amount $wallet;
    private readonly Number $traffic;
    private readonly Number $days;
    private readonly PrizePlan $plan;
    private readonly Choice $entries;
    private readonly Toggle $excludeAgents;

    public function __construct(private readonly Settings $settings)
    {
        $kinds = [RewardKind::Wallet, RewardKind::Traffic, RewardKind::Days, RewardKind::Plan];
        $this->enabled = new Toggle('lottery_enabled', 'lottery.enabled', default: false);
        $this->hour = new Number('lottery_hour', 'lottery.hour', default: 22, label: 'ساعت قرعه‌کشی', min: 0, max: 23);
        $this->winners = new Number('lottery_winners', 'lottery.winners', default: 1, label: 'تعداد برنده‌ها', min: 1, max: 20);
        $this->prize = new Choice(
            'lottery_prize',
            'lottery.prize',
            RewardKind::Wallet->value,
            array_map(static fn(RewardKind $kind): string => $kind->value, $kinds),
            'نوع جایزه را از گزینه‌ها انتخاب کنید.',
            new FieldSpec('نوع جایزه', options: array_combine(array_map(static fn(RewardKind $kind): string => $kind->value, $kinds), array_map(static fn(RewardKind $kind): string => $kind->label(), $kinds))),
        );
        $this->wallet = new Amount('lottery_wallet', 'lottery.wallet', '50000', 'مبلغ جایزه', (string) Reward::WALLET_MIN, self::WALLET_MAX, new FieldSpec('مبلغ جایزه', when: ['lottery_prize' => [RewardKind::Wallet->value]]));
        $this->traffic = new Number('lottery_traffic_gb', 'lottery.traffic_gb', default: 10, label: 'حجم جایزه', min: 1, max: 10_000, unit: 'گیگابایت', spec: new FieldSpec('حجم جایزه', when: ['lottery_prize' => [RewardKind::Traffic->value]]));
        $this->days = new Number('lottery_days', 'lottery.days', default: 7, label: 'زمان جایزه', min: 1, max: 365, unit: 'روز', spec: new FieldSpec('زمان جایزه', when: ['lottery_prize' => [RewardKind::Days->value]]));
        $this->plan = new PrizePlan('lottery_plan_id', 'lottery.plan_id', new FieldSpec('پلن جایزه', when: ['lottery_prize' => [RewardKind::Plan->value]]));
        $this->entries = new Choice(
            'lottery_entries',
            'lottery.entries',
            self::PER_ORDER,
            [self::PER_ORDER, self::PER_BUYER],
            'شانس شرکت‌کننده‌ها را از گزینه‌ها انتخاب کنید.',
            new FieldSpec('شانس هر شرکت‌کننده', options: [self::PER_ORDER => 'هر خرید یک شانس', self::PER_BUYER => 'هر خریدار یک شانس']),
        );
        $this->excludeAgents = new Toggle('lottery_exclude_agents', 'lottery.exclude_agents', default: true);
    }

    public function groups(): array
    {
        return [new Form('lottery', [$this->enabled, $this->hour, $this->winners, $this->prize, $this->wallet, $this->traffic, $this->days, $this->plan, $this->entries, $this->excludeAgents])];
    }

    public function enabled(): bool
    {
        return $this->settings->read($this->enabled);
    }

    /** The hour of the shop's day (its zone's, 0 to 23) the night's draw is due from. */
    public function hour(): int
    {
        return $this->settings->read($this->hour);
    }

    /** How many winners a draw has at most. */
    public function winners(): int
    {
        return $this->settings->read($this->winners);
    }

    /** The prize as set now; null for a plan deleted since it was chosen — no prize a draw can give. */
    public function prize(): ?Reward
    {
        $kind = RewardKind::from($this->settings->read($this->prize));
        $planId = $kind === RewardKind::Plan ? $this->settings->read($this->plan) : null;
        $plan = $planId === null ? null : Plan::query()->find($planId);

        return Reward::of($kind, match ($kind) {
            RewardKind::Wallet => $this->settings->read($this->wallet),
            RewardKind::Traffic => (string) $this->settings->read($this->traffic),
            RewardKind::Days => (string) $this->settings->read($this->days),
            default => null,
        }, $plan);
    }

    /** Whether each buyer gets one entry however much they bought (else one per paid order). */
    public function perBuyer(): bool
    {
        return $this->settings->read($this->entries) === self::PER_BUYER;
    }

    /** Whether agents are left out of the draw. */
    public function excludesAgents(): bool
    {
        return $this->settings->read($this->excludeAgents);
    }
}
