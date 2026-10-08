<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * A client as the panel reports it: limits, live counters, and the one thing the customer gets — the subscription link.
 */
final class ClientInfo
{
    public readonly Expiry $expiry;

    public function __construct(
        public readonly string $name,
        public readonly bool $enabled,
        public readonly int $uploadBytes = 0,
        public readonly int $downloadBytes = 0,
        /** 0 = unlimited. */
        public readonly int $totalBytes = 0,
        ?Expiry $expiry = null,
        /** The full link client apps subscribe to; null = the panel serves none for this client. */
        public readonly ?string $subscriptionUrl = null,
        /** Connected right now; null = the panel cannot tell, or was not asked (ProviderInterface::findClient()). */
        public readonly ?bool $online = null,
        public readonly ?\DateTimeImmutable $lastOnlineAt = null,
    ) {
        $this->expiry = $expiry ?? Expiry::never();
    }
}
