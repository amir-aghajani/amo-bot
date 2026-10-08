<?php

declare(strict_types=1);

namespace App\Modules\Providers\Drivers\ThreeXui\Support;

use App\Modules\Providers\DTO\Expiry;

/**
 * A client's `expiryTime` as 3x-ui keeps it, both ways: unix milliseconds for a deadline, 0 for never, and — the panel's
 * "expire on first use" — a negative term in milliseconds, which the panel turns into a deadline on the client's first
 * traffic.
 */
final class ExpiryTime
{
    public static function toExpiry(int $millis): Expiry
    {
        return match (true) {
            $millis > 0 => Expiry::at(new \DateTimeImmutable('@' . intdiv($millis, 1000))),
            $millis < 0 => Expiry::afterFirstUse(max(1, intdiv(-$millis, 1000))),
            default => Expiry::never(),
        };
    }

    public static function of(Expiry $expiry): int
    {
        $pending = $expiry->pendingSeconds();

        return $pending !== null ? -$pending * 1000 : ($expiry->deadline()?->getTimestamp() ?? 0) * 1000;
    }
}
