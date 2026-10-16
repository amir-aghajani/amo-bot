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
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
        public readonly string $callbackUrl,
        public readonly string $description,
        public readonly ?string $telegramId = null,
        public readonly ?string $username = null,
    ) {}
}
