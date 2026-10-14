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
}
