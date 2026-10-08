<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

enum OrderType: string
{
    case Purchase = 'purchase';         // new subscription from a plan
    case Renewal = 'renewal';           // extend an existing subscription
    case WalletTopUp = 'wallet_topup';  // add balance to the wallet
    case Traffic = 'traffic';           // an agent's traffic, for the services their own bot sells (main bot only)

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'خرید',
            self::Renewal => 'تمدید',
            self::WalletTopUp => 'شارژ کیف پول',
            self::Traffic => 'خرید حجم نمایندگی',
        };
    }
}
