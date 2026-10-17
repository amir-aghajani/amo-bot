<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** The shop's clock: when its day began, and a moment the panels send read on it — both in UTC. */
final class LocalTimeTest extends TestCase
{
    protected function tearDown(): void
    {
        LocalTime::use('UTC');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testTodayBeganAtTheShopsMidnight(): void
    {
        LocalTime::use('Asia/Tehran');
        Carbon::setTestNow('2026-10-13 21:00:00');

        self::assertSame('2026-10-13 20:30:00', LocalTime::midnight()->format('Y-m-d H:i:s'), 'half past nine in UTC is already the 14th in Tehran');
        self::assertSame('UTC', LocalTime::midnight()->getTimezone()->getName());
        self::assertSame('2026-10-12 20:30:00', LocalTime::midnight(new \DateTimeImmutable('2026-10-13 20:29:59', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'a moment of its own');

        LocalTime::use('UTC');
        self::assertSame('2026-10-13 00:00:00', LocalTime::midnight()->format('Y-m-d H:i:s'));
    }

    public function testAMomentThePanelsSendIsReadOnTheShopsClock(): void
    {
        LocalTime::use('Asia/Tehran');

        foreach (['2026-03-20T18:00', ' ۲۰۲۶-۰۳-۲۰T۱۸:۰۰ '] as $sent) {
            $moment = LocalTime::moment($sent);
            self::assertInstanceOf(Carbon::class, $moment, $sent);
            self::assertSame(['2026-03-20 14:30:00', 'UTC'], [$moment->format('Y-m-d H:i:s'), $moment->getTimezone()->getName()], 'six in the evening in Tehran; Persian digits and spaces around too');
        }
        foreach ([null, '', '  '] as $blank) {
            self::assertNull(LocalTime::moment($blank), 'none: ' . var_export($blank, true));
        }
        foreach (['2026-02-30T10:00', '2026-03-20', '2026-03-20 18:00', '2026-03-20T18:00:00', 1774018800, ['2026-03-20T18:00']] as $refused) {
            self::assertFalse(LocalTime::moment($refused), 'refused: ' . var_export($refused, true));
        }
    }

    /** An end typed past LAST_DAY is too late on any shop's clock, and the last it may be is one MySQL's TIMESTAMP keeps. */
    public function testAMomentPastTheLastDayIsTooLateOnAnyClock(): void
    {
        $timestampEnds = new \DateTimeImmutable('2038-01-19 03:14:07', new \DateTimeZone('UTC'));
        foreach (['Asia/Tehran', 'Pacific/Kiritimati', 'Etc/GMT+12', 'UTC'] as $zone) {
            LocalTime::use($zone);
            $last = LocalTime::moment(LocalTime::LAST_DAY . 'T23:59');
            $next = LocalTime::moment('2038-01-18T00:01');
            self::assertInstanceOf(Carbon::class, $last);
            self::assertInstanceOf(Carbon::class, $next);

            self::assertFalse(LocalTime::tooLate($last), "{$zone}: the last day's last minute");
            self::assertTrue($last < $timestampEnds, "{$zone}: kept before the TIMESTAMP ends");
            self::assertTrue(LocalTime::tooLate($next), "{$zone}: the next day");
            self::assertTrue(LocalTime::tooLate(new \DateTimeImmutable('9999-12-31 00:00')));
            self::assertFalse(LocalTime::tooLate(new \DateTimeImmutable('2026-10-16 12:00')));
        }

        LocalTime::use('Asia/Tehran');
        self::assertSame('زمان پایان نمی‌تواند بعد از ۲۸ دی ۱۴۱۶ باشد', LocalTime::tooLateFor('زمان پایان'));
    }
}
