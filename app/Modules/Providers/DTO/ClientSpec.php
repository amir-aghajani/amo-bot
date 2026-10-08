<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * The client the shop wants on a panel, whatever the panel — switched on: a client is made, and updated, to be used
 * (switching one off or on again is ProviderInterface::setClientEnabled()). It carries no credentials: a new client's
 * UUID, password and subscription id are the panel's to mint.
 */
final class ClientSpec
{
    public readonly Expiry $expiry;

    public function __construct(
        /** The client's identifier on the panel (whatever the driver calls it); unique per server. */
        public readonly string $name,
        /** 0 = unlimited. */
        public readonly int $totalBytes = 0,
        ?Expiry $expiry = null,
        /** 0 = unlimited concurrent IPs. */
        public readonly int $ipLimit = 0,
        /** Telegram id, stored on the panel so its client list points back at the account; null for a customer without one. */
        public readonly ?int $telegramId = null,
        public readonly ?string $comment = null,
    ) {
        $this->expiry = $expiry ?? Expiry::never();
    }
}
