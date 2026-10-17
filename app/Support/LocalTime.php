<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The shop's time zone (APP_TIMEZONE). Every stored time is UTC (PHP's clock and the database session); this zone is
 * applied only where a time is shown or read (the bot's Jalali dates, the dashboard's days, a day's cap, a moment the
 * panels send, the log), so changing it changes how times read, never what they are.
 */
final class LocalTime
{
    /** A moment as the panels send it: a day and a minute on the shop's clock, whatever the browser's zone. */
    public const MOMENT = 'Y-m-d\TH:i';

    /**
     * The last day a moment an owner types may fall on (a sale's or a discount code's window, an agency's end, a gift
     * code's last day): the shop's times are TIMESTAMP columns, which MySQL ends at 2038-01-19 03:14:07 UTC and refuses
     * past (strict mode) — two days before, which ends in time on any shop's clock (UTC−12 too).
     */
    public const LAST_DAY = '2038-01-17';

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

    /** When the day of `$moment` (now when none) began on the shop's clock, in UTC: what a cap of a day counts from. */
    public static function midnight(?\DateTimeInterface $moment = null): Carbon
    {
        return Carbon::instance(self::of($moment ?? Carbon::now())->setTime(0, 0))->utc();
    }

    /**
     * A moment the panels send (MOMENT: "2026-03-20T18:00", Persian digits allowed) on the shop's clock, in UTC; null
     * when blank or left out, false for anything else — a day that does not exist (the 30th of February), text of
     * another shape, a number, a list.
     */
    public static function moment(mixed $typed): Carbon|false|null
    {
        if ($typed === null || (is_string($typed) && trim($typed) === '')) {
            return null;
        }
        $text = is_string($typed) ? Persian::latinDigits(trim($typed)) : '';
        $moment = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $text) === 1 ? Carbon::createFromFormat(self::MOMENT, $text, self::zone()) : null;

        // A day that does not exist parses into another one: compared back, it is not the text sent.
        return $moment instanceof Carbon && $moment->format(self::MOMENT) === $text ? $moment->utc() : false;
    }

    /** The latest moment the shop keeps: the end of LAST_DAY on its clock, in UTC. */
    public static function last(): Carbon
    {
        return Carbon::instance(new \DateTimeImmutable(self::LAST_DAY, self::zone()))->addDay()->utc();
    }

    /** Whether `$moment` is later than the shop can keep: past the end of LAST_DAY on its clock (last()). */
    public static function tooLate(\DateTimeInterface $moment): bool
    {
        return $moment > self::last();
    }

    /**
     * «تاریخ پایان نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد» — the refusal of a moment tooLate() for `$what` (the field's name),
     * for the caller to end as its field's guidance goes on.
     */
    public static function tooLateFor(string $what): string
    {
        return sprintf('%s نمی‌تواند بعد از %s باشد', $what, Persian::date(new \DateTimeImmutable(self::LAST_DAY . ' 12:00', self::zone())));
    }
}
