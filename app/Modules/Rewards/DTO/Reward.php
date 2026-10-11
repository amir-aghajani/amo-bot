<?php

declare(strict_types=1);

namespace App\Modules\Rewards\DTO;

use App\Modules\Catalog\Models\Plan;
use App\Modules\Rewards\Enums\RewardKind;
use App\Modules\Telegram\Messages;
use App\Support\Money;
use App\Support\Persian;
use App\Support\Traffic;

/** One reward to give (Services\Rewards::give()): its kind and how much — Toman, GB or days — or the plan given free. */
final readonly class Reward
{
    /** The least Toman a wallet prize or gift may be. */
    public const WALLET_MIN = 1_000;

    private function __construct(
        public RewardKind $kind,
        /** Toman (wallet), GB (traffic, decimals allowed), whole days (days); null for nothing and a plan. */
        public ?string $amount,
        public ?Plan $plan,
    ) {}

    public static function nothing(): self
    {
        return new self(RewardKind::Nothing, null, null);
    }

    public static function wallet(string|int $toman): self
    {
        return new self(RewardKind::Wallet, Money::normalize($toman), null);
    }

    public static function traffic(string|int|float $gb): self
    {
        return new self(RewardKind::Traffic, (string) $gb, null);
    }

    public static function days(int $days): self
    {
        return new self(RewardKind::Days, (string) $days, null);
    }

    public static function plan(Plan $plan): self
    {
        return new self(RewardKind::Plan, null, $plan);
    }

    /**
     * A reward as a row keeps it (a wheel's slice, a draw's prize, a gift code); null for a plan deleted since, which can
     * be given to nobody.
     */
    public static function of(RewardKind $kind, ?string $amount, ?Plan $plan): ?self
    {
        return match ($kind) {
            RewardKind::Nothing => self::nothing(),
            RewardKind::Wallet => self::wallet($amount ?? '0'),
            RewardKind::Traffic => self::traffic($amount ?? '0'),
            RewardKind::Days => self::days((int) ($amount ?? 0)),
            RewardKind::Plan => $plan === null ? null : self::plan($plan),
        };
    }

    /** The traffic it gives, in bytes (0 unless traffic). */
    public function bytes(): int
    {
        return $this->kind === RewardKind::Traffic ? Traffic::bytesOfGb($this->amount ?? '0') : 0;
    }

    /** The days it gives (0 unless days). */
    public function dayCount(): int
    {
        return $this->kind === RewardKind::Days ? (int) $this->amount : 0;
    }

    /** What it gives, worded for the customer: «۵۰٬۰۰۰ تومان شارژ کیف پول», «۱۰ گیگابایت حجم», «۷ روز زمان», «سرویس «X»». */
    public function words(): string
    {
        return match ($this->kind) {
            RewardKind::Nothing => 'پوچ',
            RewardKind::Wallet => Money::format($this->amount ?? '0') . ' شارژ کیف پول',
            RewardKind::Traffic => Messages::bytes($this->bytes()) . ' حجم',
            RewardKind::Days => Persian::number($this->dayCount()) . ' روز زمان',
            RewardKind::Plan => 'سرویس «' . ($this->plan->name ?? '') . '»',
        };
    }
}
