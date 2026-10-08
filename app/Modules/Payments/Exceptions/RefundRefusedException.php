<?php

declare(strict_types=1);

namespace App\Modules\Payments\Exceptions;

use App\Core\Exceptions\DomainRuleException;
use App\Modules\Telegram\Messages;
use App\Support\Money;

/**
 * A refund that would take back what its order put in the shop — a wallet top-up's credit, an agent's traffic — when
 * that is no longer there: spent, or sold by the agent's bot since. Nothing was written; the message says what is left.
 */
final class RefundRefusedException extends DomainRuleException
{
    public static function topUpSpent(string $balance): self
    {
        return new self(sprintf('این شارژ خرج شده است: موجودی کیف پول مشتری %s است و مبلغ شارژ از آن کم نمی‌شود.', Money::format($balance)));
    }

    public static function trafficSold(int $left): self
    {
        return new self(sprintf('حجم این خرید فروخته شده است: حجم باقی‌مانده نماینده %s است و حجم این سفارش از آن کم نمی‌شود.', Messages::bytes($left)));
    }
}
