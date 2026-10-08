<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BankCard;
use PHPUnit\Framework\TestCase;

final class BankCardTest extends TestCase
{
    public function testNormalizeAcceptsPersianDigitsSpacesAndDashes(): void
    {
        self::assertSame('6037997700001119', BankCard::normalize('۶۰۳۷-۹۹۷۷ ۰۰۰۰ ۱۱۱۹'));
        self::assertSame('6037997700001119', BankCard::normalize(' 6037 9977 0000 1119 '));
        self::assertSame('', BankCard::normalize('abc'));
    }

    public function testValidityIsSixteenDigitsWithALuhnCheck(): void
    {
        self::assertTrue(BankCard::isValid('6037997700001119'));
        self::assertFalse(BankCard::isValid('6037997700001111'), 'wrong check digit');
        self::assertFalse(BankCard::isValid('603799770000111'), 'too short');
        self::assertFalse(BankCard::isValid('6037-9977-0000-1119'), 'normalize first');
    }

    public function testMaskingKeepsTheEndsOnly(): void
    {
        self::assertSame('6037 •••• •••• 1119', BankCard::mask('6037997700001119'));
        self::assertSame('1234', BankCard::mask('1234'));
    }
}
