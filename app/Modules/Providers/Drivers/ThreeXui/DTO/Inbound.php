<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\DTO;

use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;

/**
 * An Xray inbound as GET /panel/api/inbounds/list/slim gives it — the slim list strips every client to its email and
 * switch, so no secret crosses the wire — as much of it as the shop keeps.
 */
final class Inbound
{
    public function __construct(
        public readonly int $id,
        public readonly string $tag,
        public readonly string $protocol,
        public readonly int $port,
        public readonly string $remark,
        public readonly bool $enable,
        /** tcp, ws, grpc, …. */
        public readonly string $network,
        /** none, tls, reality. */
        public readonly string $security,
        public readonly int $clientCount,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $stream = Raw::json($raw, 'streamSettings');

        return new self(
            id: Raw::int($raw, 'id'),
            tag: Raw::string($raw, 'tag'),
            protocol: Raw::string($raw, 'protocol'),
            port: Raw::int($raw, 'port'),
            remark: Raw::string($raw, 'remark'),
            enable: Raw::bool($raw, 'enable', true),
            network: Raw::string($stream, 'network', 'tcp'),
            security: Raw::string($stream, 'security', 'none'),
            clientCount: count(Raw::objectsOf(Raw::json($raw, 'settings')['clients'] ?? null)),
        );
    }
}
