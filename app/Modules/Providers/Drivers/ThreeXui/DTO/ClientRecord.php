<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\DTO;

use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;

/**
 * A client row as /panel/api/clients reads it: the identity, the limits — and every protocol field the panel keeps,
 * because /update replaces the whole row and what it is not sent back is lost (ClientPayload::fromRecord()). The list
 * rows carry their counters (`traffic`); /get's do not.
 *
 * The panel keeps the historical field name `totalGB`, but the value is bytes.
 */
final class ClientRecord
{
    /** @param array<string, mixed>|null $reverse */
    public function __construct(
        public readonly string $email,
        /** vless / vmess id ("uuid" on read, "id" on write). */
        public readonly string $uuid = '',
        /** trojan / shadowsocks password. */
        public readonly string $password = '',
        /** hysteria password. */
        public readonly string $auth = '',
        /** mtproto FakeTLS secret. */
        public readonly string $secret = '',
        public readonly string $subId = '',
        public readonly bool $enable = true,
        /** Quota in bytes, 0 = unlimited. */
        public readonly int $totalBytes = 0,
        /** See Support\ExpiryTime. */
        public readonly int $expiryTime = 0,
        public readonly int $limitIp = 0,
        public readonly int $limitHwid = 0,
        public readonly int $tgId = 0,
        public readonly string $comment = '',
        public readonly string $flow = '',
        public readonly string $group = '',
        public readonly string $security = '',
        public readonly int $reset = 0,
        public readonly int $resetDay = 0,
        public readonly int $resetMax = 0,
        public readonly string $trafficReset = '',
        public readonly int $trafficResetDay = 0,
        /** WireGuard addresses, "a, b" (a list on write). */
        public readonly string $allowedIPs = '',
        public readonly string $publicKey = '',
        public readonly string $privateKey = '',
        public readonly string $preSharedKey = '',
        public readonly int $keepAlive = 0,
        public readonly string $forwardedPorts = '',
        public readonly string $adTag = '',
        public readonly ?array $reverse = null,
        public readonly ?Traffic $traffic = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $traffic = Raw::nullableArray($raw, 'traffic');

        return new self(
            email: Raw::string($raw, 'email'),
            uuid: Raw::string($raw, 'uuid'),
            password: Raw::string($raw, 'password'),
            auth: Raw::string($raw, 'auth'),
            secret: Raw::string($raw, 'secret'),
            subId: Raw::string($raw, 'subId'),
            enable: Raw::bool($raw, 'enable', true),
            totalBytes: Raw::int($raw, 'totalGB'),
            expiryTime: Raw::int($raw, 'expiryTime'),
            limitIp: Raw::int($raw, 'limitIp'),
            limitHwid: Raw::int($raw, 'limitHwid'),
            tgId: Raw::int($raw, 'tgId'),
            comment: Raw::string($raw, 'comment'),
            flow: Raw::string($raw, 'flow'),
            group: Raw::string($raw, 'group'),
            security: Raw::string($raw, 'security'),
            reset: Raw::int($raw, 'reset'),
            resetDay: Raw::int($raw, 'resetDay'),
            resetMax: Raw::int($raw, 'resetMax'),
            trafficReset: Raw::string($raw, 'trafficReset'),
            trafficResetDay: Raw::int($raw, 'trafficResetDay'),
            allowedIPs: self::joined($raw['allowedIPs'] ?? null),
            publicKey: Raw::string($raw, 'publicKey'),
            privateKey: Raw::string($raw, 'privateKey'),
            preSharedKey: Raw::string($raw, 'preSharedKey'),
            keepAlive: Raw::int($raw, 'keepAlive'),
            forwardedPorts: Raw::string($raw, 'forwardedPorts'),
            adTag: Raw::string($raw, 'adTag'),
            reverse: Raw::nullableArray($raw, 'reverse'),
            traffic: $traffic !== null ? Traffic::fromArray($traffic) : null,
        );
    }

    /** WireGuard's allowedIPs come as a list on write and a string on read: one form, "a, b". */
    private static function joined(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map(strval(...), array_filter($value, is_scalar(...)))) : (is_scalar($value) ? (string) $value : '');
    }
}
