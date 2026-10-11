<?php

declare(strict_types=1);

namespace App\Modules\Rewards\Enums;

/**
 * What a prize or gift gives (Services\Rewards): nothing (an empty wheel slice), Toman, traffic or days on a service, or
 * a plan's free service.
 */
enum RewardKind: string
{
    case Nothing = 'nothing';
    case Wallet = 'wallet';
    case Traffic = 'traffic';
    case Days = 'days';
    case Plan = 'plan';

    /** The kind worded for the admin. */
    public function label(): string
    {
        return match ($this) {
            self::Nothing => 'پوچ',
            self::Wallet => 'شارژ کیف پول',
            self::Traffic => 'حجم',
            self::Days => 'زمان',
            self::Plan => 'سرویس رایگان',
        };
    }

    /** Whether it is given to one of the customer's services, which they must have running. */
    public function onService(): bool
    {
        return $this === self::Traffic || $this === self::Days;
    }
}
