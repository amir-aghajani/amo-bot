<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * The client the shop wants on a panel, whatever the panel. A client is made, and an update leaves it, switched on or
 * off as `$enabled` says — a service moved while support had it switched off is made switched off on its new server,
 * and a change that is no switch (a relabel, a refund's take-back) never turns on one that support or the panel
 * switched off (switching is ProviderInterface::setClientEnabled()). It carries no credentials: a new client's UUID,
 * password and subscription id are the panel's to mint.
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
        /** Switched on, or off: as it is made, and after an update. */
        public readonly bool $enabled = true,
    ) {
        $this->expiry = $expiry ?? Expiry::never();
    }
}
