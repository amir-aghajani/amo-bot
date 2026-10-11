<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Enums;

/** What a gift code gives: Toman for the wallet, or a plan's free service. */
enum GiftCodeKind: string
{
    case Wallet = 'wallet';
    case Plan = 'plan';

    /** The kind of reward it gives (Rewards\Services\Rewards). */
    public function reward(): RewardKind
    {
        return match ($this) {
            self::Wallet => RewardKind::Wallet,
            self::Plan => RewardKind::Plan,
        };
    }
}
