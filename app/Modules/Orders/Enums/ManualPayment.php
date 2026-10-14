<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

/**
 * How support pays an order it makes by hand for a customer (Services\ManualOrders): as a gift, from the customer's
 * wallet, or with money it took outside the shop (cash, another card) — the shop's takings like a card's.
 */
enum ManualPayment: string
{
    /** A gift: nothing paid, and no purchase of the customer's (`pricing` gift). */
    case Free = 'free';
    /** Support's amount, charged to the customer's wallet. */
    case Wallet = 'wallet';
    /** Support's amount, paid outside the shop: settled by the outside row. */
    case Outside = 'outside';

    /** In words, as the report group and the panels say it. */
    public function label(): string
    {
        return match ($this) {
            self::Free => 'رایگان',
            self::Wallet => 'از کیف پول مشتری',
            self::Outside => 'پرداخت خارج از فروشگاه',
        };
    }
}
