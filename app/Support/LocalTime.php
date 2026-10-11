<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shop's time zone (APP_TIMEZONE). Every stored time is UTC (PHP's clock and the database session); this zone is
 * applied only where a time is shown (the bot's Jalali dates, the dashboard's days, the log), so changing it changes how
 * times read, never what they are.
 */
final class LocalTime
{
    private static ?\DateTimeZone $zone = null;

    /** Set once at boot; a name PHP does not know falls back to UTC. */
    public static function use(string $name): void
    {
        try {
            self::$zone = new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Exception) {
            self::$zone = new \DateTimeZone('UTC');
        }
    }

    public static function zone(): \DateTimeZone
    {
        return self::$zone ??= new \DateTimeZone('UTC');
    }

    /** A moment as the shop reads it. */
    public static function of(\DateTimeInterface $moment): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($moment)->setTimezone(self::zone());
    }

    /** The zone's offset from UTC now, in seconds (Tehran: 12600). */
    public static function offset(): int
    {
        return self::zone()->getOffset(new \DateTimeImmutable('now', self::zone()));
    }
}
