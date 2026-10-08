<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Traffic is bytes (int) everywhere; the admin types gigabytes. This is the one conversion.
 */
final class Traffic
{
    public const MEGABYTE = 1024 ** 2;
    public const GIGABYTE = 1024 ** 3;

    /** Gigabytes as the admin typed them (a plan's quota, a grant) in bytes. */
    public static function bytesOfGb(float|int|string $gigabytes): int
    {
        return (int) round((float) $gigabytes * self::GIGABYTE);
    }
}
