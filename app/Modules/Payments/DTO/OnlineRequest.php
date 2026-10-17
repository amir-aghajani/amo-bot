<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTO;

/**
 * What the shop tells an online gateway as it opens a payment there (Contracts\OnlineGateway::open()): the payment's id
 * at the gateway — one per payment, never another installation's (Services\OnlineInvoices::orderId()) —, the amount in
 * whole Toman, the address the gateway calls back (Services\OnlineInvoices::callbackUrl()), a line saying what it pays,
 * and who pays: their numeric Telegram id and username, when they have them.
 */
final class OnlineRequest
{
    /** The query word that makes the callback address the page leading on to the gateway's (viaShop()). */
    public const VIA_SHOP = 'go';

    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly string $callbackUrl,
        public readonly string $description,
        public readonly ?string $telegramId = null,
        public readonly ?string $username = null,
    ) {}

    /**
     * The shop's own page that leads the customer on to the gateway's (Controllers\ReturnPage::go()), at the callback
     * address: the page to send them to for a gateway that takes them only from the shop's address. The gateway's own
     * page is then kept in the invoice's details as `page`.
     */
    public function viaShop(): string
    {
        return $this->callbackUrl . '?' . self::VIA_SHOP . '=1';
    }
}
