<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\JalaliMonth;
use App\Support\LocalTime;
use App\Support\Persian;
use PHPUnit\Framework\TestCase;

/** A Jalali month on the shop's clock: its key and name, and its first and last moment in UTC, whatever its length. */
final class JalaliMonthTest extends TestCase
{
    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testAMonthBeginsAndEndsAtTheShopsMidnights(): void
    {
        LocalTime::use('Asia/Tehran');

        // 20 Oct 2026, 12:00 UTC: 28 Mehr 1405 in Tehran.
        $mehr = JalaliMonth::of(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['1405-07', 'مهر ۱۴۰۵'], [$mehr->key(), $mehr->name()]);
        self::assertSame('2026-09-22 20:30:00', $mehr->start->format('Y-m-d H:i:s'), "1 Mehr's midnight in Tehran, in UTC");
        self::assertSame('2026-10-22 20:30:00', $mehr->end->format('Y-m-d H:i:s'), "30 days on: 1 Aban's midnight");
        self::assertSame('UTC', $mehr->start->getTimezone()->getName());
        self::assertSame(3, $mehr->daysLeft(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC'))), 'two days and a part');
        self::assertSame(0, $mehr->daysLeft($mehr->end));
    }

    public function testAMomentFallsInTheMonthOfTheShopsClock(): void
    {
        LocalTime::use('Asia/Tehran');

        // 22 Oct 2026, 21:00 UTC is already 1 Aban in Tehran; in UTC it is still Mehr.
        $moment = new \DateTimeImmutable('2026-10-22 21:00:00', new \DateTimeZone('UTC'));
        self::assertSame('1405-08', JalaliMonth::of($moment)->key());

        LocalTime::use('UTC');
        self::assertSame('1405-07', JalaliMonth::of($moment)->key());
    }

    public function testMonthsAre31ThenThirtyThenEsfandsDaysLong(): void
    {
        LocalTime::use('Asia/Tehran');
        $days = static fn(JalaliMonth $month): int => intdiv($month->end->getTimestamp() - $month->start->getTimestamp(), 86_400);

        $shahrivar = JalaliMonth::of(new \DateTimeImmutable('2026-09-01 12:00:00', new \DateTimeZone('UTC')));
        self::assertSame(['1405-06', 31], [$shahrivar->key(), $days($shahrivar)]);
        $esfand = JalaliMonth::of(new \DateTimeImmutable('2026-03-01 12:00:00', new \DateTimeZone('UTC')));
        self::assertSame(['1404-12', 29], [$esfand->key(), $days($esfand)], '1404 is no leap year');

        self::assertSame('1404-11', $esfand->previous()->key());
        self::assertSame('1405-06', JalaliMonth::of($shahrivar->end)->previous()->key(), "the next month's previous is this one");
        self::assertSame('1404-12', JalaliMonth::of(new \DateTimeImmutable('2026-03-21 12:00:00', new \DateTimeZone('UTC')))->previous()->key(), "Farvardin's previous is last year's Esfand");
        self::assertTrue(strcmp('1404-12', '1405-01') < 0, 'keys compare as their months do');
    }

    public function testALeapYearsEsfandHasThirtyDaysAndItsLastOneIsItsOwn(): void
    {
        LocalTime::use('Asia/Tehran');

        // 20 Mar 2025, 20:29 UTC: 23:59 on 30 Esfand 1403 in Tehran — the leap year's last day, as the bot dates it.
        $last = new \DateTimeImmutable('2025-03-20 20:29:00', new \DateTimeZone('UTC'));
        $esfand = JalaliMonth::of($last);
        self::assertSame([1403, 12, 30], Persian::jalali($last));
        self::assertSame(['1403-12', '2025-02-18 20:30:00', '2025-03-20 20:30:00'], [$esfand->key(), $esfand->start->format('Y-m-d H:i:s'), $esfand->end->format('Y-m-d H:i:s')], '30 days');
        self::assertSame(1, $esfand->daysLeft($last), 'a minute is a part of a day');

        $farvardin = JalaliMonth::of($esfand->end);
        self::assertSame(['1404-01', '1403-12'], [$farvardin->key(), $farvardin->previous()->key()], 'its end is the new year');
        self::assertSame([1404, 1, 1], Persian::jalali($esfand->end));
    }
}
