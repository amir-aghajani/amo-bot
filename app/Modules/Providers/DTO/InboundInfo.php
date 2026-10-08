<?php

declare(strict_types=1);

namespace App\Modules\Providers\DTO;

/**
 * What a panel attaches clients to — an inbound (3x-ui), a group of them (PasarGuard) — as much of it as the admin picks
 * by and the shop stores. Never the protocol settings: they carry every client's secrets. What a panel has no notion of
 * is null, never a made-up value.
 */
final class InboundInfo
{
    public function __construct(
        /** The driver's own identifier for it, opaque to the shop. */
        public readonly string $key,
        public readonly string $tag,
        /** What the admin calls it on the panel. */
        public readonly string $remark,
        public readonly bool $enabled,
        /** vless, vmess, trojan, …; null for one that is not a single protocol (a PasarGuard group). */
        public readonly ?string $protocol = null,
        public readonly ?int $port = null,
        /** Transport (tcp, ws, grpc, …). */
        public readonly ?string $network = null,
        /** none, tls, reality, …. */
        public readonly ?string $security = null,
        public readonly int $clientCount = 0,
    ) {}
}
