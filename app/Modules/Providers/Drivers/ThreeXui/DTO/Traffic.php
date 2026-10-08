<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\DTO;

use App\Modules\Providers\Drivers\ThreeXui\Support\Raw;

/**
 * A client's counters, as the traffic block of a list row or GET /clients/traffic/{email} gives them: bytes up and down,
 * and when it was last online (unix ms, 0 = never).
 */
final class Traffic
{
    public function __construct(
        public readonly int $up,
        public readonly int $down,
        public readonly int $lastOnline = 0,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(up: Raw::int($raw, 'up'), down: Raw::int($raw, 'down'), lastOnline: Raw::int($raw, 'lastOnline'));
    }
}
