<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testNormalizeGivesTheStoredFormWhateverTheAmountCameAs(): void
    {
        self::assertSame('120000.00', Money::normalize('120000'));
        self::assertSame('12.50', Money::normalize('12.5'));
        self::assertSame('12.50', Money::normalize(12.5));
        self::assertSame('7.00', Money::normalize(7));
        self::assertSame('120000.00', Money::normalize('۱۲۰٬۰۰۰'), 'Persian digits and the Persian thousands mark');
        self::assertSame('12.50', Money::normalize('۱۲٫۵'), 'the Persian decimal mark');
        self::assertSame('1.00', Money::normalize(' 1 '));
    }

    public function testNormalizeRoundsAThirdDecimalHalfUp(): void
    {
        self::assertSame('12.35', Money::normalize('12.345'));
        self::assertSame('12.34', Money::normalize('12.344'));
        self::assertSame('-12.35', Money::normalize('-12.345'));
        self::assertSame('100000000000000.00', Money::normalize(1e14), 'a float is not written in exponent form');
    }

    public function testNormalizeRefusesTextThatIsNotAnAmount(): void
    {
        try {
            $normalised = Money::normalize('abc');
            self::fail("'abc' is not an amount, yet it became {$normalised}");
        } catch (\ValueError) {
            $this->addToAssertionCount(1);
        }
    }

    public function testArithmeticAndComparisonUseTheNormalisedForms(): void
    {
        self::assertSame('25.00', Money::add('10', 15));
        self::assertSame('14.50', Money::subtract(25, '10.50'));
        self::assertSame(0, Money::compare('12.50', 12.5));
        self::assertSame(-1, Money::compare('۱۰', '11'));
        self::assertSame(1, Money::compare('11', 10.994));
        self::assertSame(0, Money::compare('11', 10.999), 'a float rounds like text does');
        self::assertTrue(Money::isPositive('0.01'));
        self::assertFalse(Money::isPositive('0'));
        self::assertFalse(Money::isPositive('-5'));
    }

    public function testFormatIsWholeTomanWithPersianDigits(): void
    {
        self::assertSame('۱۲۰٬۰۰۰ تومان', Money::format('120000.00'));
        self::assertSame('۱۲۰٬۰۰۰ تومان', Money::format('۱۲۰۰۰۰'));
        self::assertSame('۱۳ تومان', Money::format('12.50'), 'no fraction, rounded');
        self::assertSame('۰ تومان', Money::format(0));
    }

    public function testSeveralOfOnePrice(): void
    {
        self::assertSame('288000.00', Money::times('96000.00', 3));
        self::assertSame('192000.00', Money::times('۹۶۰۰۰', 2), 'Persian digits too');
        self::assertSame('0.00', Money::times('96000', 0));
    }

    public function testAPercentOfAnAmountIsWholeTomanRoundedDown(): void
    {
        self::assertSame('12000.00', Money::percentOf('120000.00', 10));
        self::assertSame('8399.00', Money::percentOf('119999', 7), '8,399.93 — the shop never pays a fraction');
        self::assertSame('0.00', Money::percentOf('9', 10));
        self::assertSame('120000.00', Money::percentOf('120000', 100));
    }
}
