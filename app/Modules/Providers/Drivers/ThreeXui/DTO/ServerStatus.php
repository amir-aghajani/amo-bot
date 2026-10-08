<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\DTO;

use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;

/**
 * GET /panel/api/server/status — the host as the panel sees it (refreshed every two seconds there), as much of it as the
 * servers screen shows. Memory and disk in bytes, CPU in percent.
 */
final class ServerStatus
{
    public function __construct(
        public readonly float $cpu,
        public readonly int $memUsed,
        public readonly int $memTotal,
        public readonly int $diskUsed,
        public readonly int $diskTotal,
        /** running | stop | error */
        public readonly string $xrayState,
        public readonly string $xrayVersion,
        public readonly string $xrayError,
        /** Open TCP and UDP connections, together. */
        public readonly int $connections,
        /** Seconds; 0 when the panel does not report it. */
        public readonly int $uptime,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $mem = Raw::array($raw, 'mem');
        $disk = Raw::array($raw, 'disk');
        $xray = Raw::array($raw, 'xray');

        return new self(
            cpu: Raw::float($raw, 'cpu'),
            memUsed: Raw::int($mem, 'current'),
            memTotal: Raw::int($mem, 'total'),
            diskUsed: Raw::int($disk, 'current'),
            diskTotal: Raw::int($disk, 'total'),
            xrayState: Raw::string($xray, 'state', 'unknown'),
            xrayVersion: Raw::string($xray, 'version'),
            xrayError: Raw::string($xray, 'errorMsg'),
            connections: Raw::int($raw, 'tcpCount') + Raw::int($raw, 'udpCount'),
            uptime: Raw::int($raw, 'uptime'),
        );
    }
}
