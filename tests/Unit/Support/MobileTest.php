<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Mobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MobileTest extends TestCase
{
    /** @return iterable<string, array{string}> An Iranian mobile however a customer types it */
    public static function typed(): iterable
    {
        yield 'with its trunk zero' => ['09121234567'];
        yield 'without it' => ['9121234567'];
        yield 'in E.164' => ['+989121234567'];
        yield 'with the international prefix' => ['00989121234567'];
        yield 'with the country code alone' => ['989121234567'];
        yield 'the trunk zero after the country code' => ['+98 0912 123 4567'];
        yield 'in Persian digits, spaced' => ['۰۹۱۲ ۱۲۳ ۴۵۶۷'];
        yield 'in Arabic digits' => ['٠٩١٢١٢٣٤٥٦٧'];
        yield 'with dashes and brackets' => ['(0912) 123-4567'];
        yield 'pasted with direction marks' => ["\u{200E}+98 912 123 4567\u{200F}"];
    }

    #[DataProvider('typed')]
    public function testAnIranianMobileIsKeptInE164HoweverItIsTyped(string $typed): void
    {
        self::assertSame('+989121234567', Mobile::of($typed));
        self::assertNull(Mobile::problem($typed));
    }

    public function testAnythingElseIsNoneAndSaysWhy(): void
    {
        self::assertSame(Mobile::MISSING, Mobile::problem('  '));
        foreach (['02112345678' => 'a landline', '+14155550100' => "another country's", '0912123456' => 'a digit short', '091212345678' => 'a digit long', '09121234567x' => 'a letter in it', '+98+9121234567' => 'two pluses', str_repeat('9', 40) => 'far too long'] as $typed => $what) {
            self::assertNull(Mobile::of((string) $typed), $what);
            self::assertSame(Mobile::NOT_A_MOBILE, Mobile::problem((string) $typed), $what);
        }
    }

    public function testALogLineNamesTheNumberMasked(): void
    {
        self::assertSame('+98912***4567', Mobile::masked('+989121234567'));
        self::assertSame('***', Mobile::masked('+98'));
    }
}
