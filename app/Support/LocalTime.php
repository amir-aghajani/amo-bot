<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shop's own time zone (APP_TIMEZONE): what people read. Every time the app keeps is UTC — PHP's clock and the
 * database's — so a stored moment means the same whatever the setting says, the host's zone or its daylight saving;
 * this zone is applied only where a time is shown to someone (a Jalali date in the bot, the dashboard's days, the log).
 * Changing the setting changes how times read, never what they are.
 */
final class LocalTime
{
    private static ?\DateTimeZone $zone = null;

    /** Set once at boot, from config; a name PHP does not know falls back to UTC rather than taking the app down. */
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
