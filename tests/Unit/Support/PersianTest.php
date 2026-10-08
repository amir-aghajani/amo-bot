<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LocalTime;
use App\Support\Persian;
use PHPUnit\Framework\TestCase;

final class PersianTest extends TestCase
{
    protected function tearDown(): void
    {
        LocalTime::use('UTC');

        parent::tearDown();
    }

    public function testMinutesReadLikePeopleSayThem(): void
    {
        self::assertSame('۳۰ دقیقه', Persian::minutes(30));
        self::assertSame('۱ ساعت', Persian::minutes(60));
        self::assertSame('۱ ساعت و ۳۰ دقیقه', Persian::minutes(90));
        self::assertSame('۲ ساعت', Persian::minutes(120));
        self::assertSame('۱ روز', Persian::minutes(1440));
        self::assertSame('۲۵ ساعت', Persian::minutes(1500));
        self::assertSame('۰ دقیقه', Persian::minutes(0));
    }

    public function testDigitsGoBothWays(): void
    {
        self::assertSame('۱۲۳', Persian::digits(123));
        self::assertSame('123', Persian::latinDigits('۱۲۳'));
        self::assertSame('123', Persian::latinDigits('١٢٣'));
        self::assertSame('1234.5', Persian::latinDigits('۱٬۲۳۴٫۵'), 'the Persian thousands mark goes, its decimal mark is a point');
    }

    public function testANumberIsGroupedInThousandsWithPersianDigits(): void
    {
        self::assertSame('۱٬۲۳۴٬۵۶۷', Persian::number(1234567));
        self::assertSame('۱۳', Persian::number(12.5), 'whole, rounded');
        self::assertSame('۰', Persian::number(0));
    }

    public function testDatesAreJalali(): void
    {
        $date = new \DateTimeImmutable('2026-09-19 14:05:00', new \DateTimeZone('UTC'));

        self::assertSame('۲۸ شهریور ۱۴۰۵', Persian::date($date));
        self::assertSame('۲۸ شهریور ۱۴۰۵، ۱۴:۰۵', Persian::date($date, withTime: true));
        self::assertSame('۱ فروردین ۱۴۰۵', Persian::date(new \DateTimeImmutable('2026-03-21')), 'Nowruz');
        self::assertSame('۳۰ اسفند ۱۴۰۳', Persian::date(new \DateTimeImmutable('2025-03-20')), 'the leap day before it');
    }

    public function testADateReadsInTheShopsZone(): void
    {
        // 21:00 UTC on 5 October is already the 6th in Tehran (+03:30).
        $moment = new \DateTimeImmutable('2026-10-05 21:00:00', new \DateTimeZone('UTC'));

        self::assertSame('۱۳ مهر ۱۴۰۵، ۲۱:۰۰', Persian::date($moment, withTime: true));

        LocalTime::use('Asia/Tehran');
        self::assertSame('۱۴ مهر ۱۴۰۵، ۰۰:۳۰', Persian::date($moment, withTime: true));
        self::assertSame(12600, LocalTime::offset());

        LocalTime::use('Not/A_Zone');
        self::assertSame('UTC', LocalTime::zone()->getName(), 'a zone PHP does not know reads as UTC');
    }
}
