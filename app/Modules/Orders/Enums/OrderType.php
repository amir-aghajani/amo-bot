<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/** What an order buys. */
enum OrderType: string
{
    case Purchase = 'purchase';         // a new service from a plan
    case Renewal = 'renewal';           // another term of a service the customer has
    case WalletTopUp = 'wallet_topup';  // Toman for the wallet
    case Traffic = 'traffic';           // an agent's traffic, for the services their own bot sells (main bot only)
    case ExtraTraffic = 'extra_traffic'; // traffic bought on top of a service the customer has
    case ExtraTime = 'extra_time';       // days bought on top of a service the customer has

    /**
     * What a customer buys for themselves: what discounts apply to, and what loyalty, cashback and the nightly draw count.
     * Never a wallet top-up (its amount is what it buys) nor an agent's traffic (their level's price is the price).
     */
    public const FOR_THEMSELVES = [self::Purchase->value, self::Renewal->value, self::ExtraTraffic->value, self::ExtraTime->value];

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'خرید',
            self::Renewal => 'تمدید',
            self::WalletTopUp => 'شارژ کیف پول',
            self::Traffic => 'خرید حجم نمایندگی',
            self::ExtraTraffic => 'حجم اضافه',
            self::ExtraTime => 'زمان اضافه',
        };
    }

    /** Whether the customer buys it for themselves (FOR_THEMSELVES). */
    public function forThemselves(): bool
    {
        return in_array($this->value, self::FOR_THEMSELVES, true);
    }

    /**
     * An order for a service the customer already has — a renewal, or extra traffic or time —: its `subscription_id` is
     * set from the start and its server is the service's.
     */
    public function forService(): bool
    {
        return match ($this) {
            self::Renewal, self::ExtraTraffic, self::ExtraTime => true,
            self::Purchase, self::WalletTopUp, self::Traffic => false,
        };
    }
}
