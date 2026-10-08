<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Email;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule for an email address: kept trimmed and in lower case — one address however it is typed —, at most 191
 * characters, and an address a mail can go to (a domain with a dot in it) and nothing but it: no quoted name, none of
 * what a mail library reads as another address or several; why it is not one, in its owner's words.
 */
final class EmailTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function addresses(): array
    {
        return [
            'as typed' => ['sara@example.com', 'sara@example.com'],
            'in capitals, with spaces around' => ['  Sara.Ahmadi+shop@Example.COM ', 'sara.ahmadi+shop@example.com'],
            'no domain' => ['sara@', null],
            'a host, not a domain' => ['sara@localhost', null],
            'two of them' => ['sara@example.com, ali@example.com', null],
            'two of them, apart by a semicolon' => ['sara@example.com;ali@example.com', null],
            'a space inside' => ['sara ahmadi@example.com', null],
            'a tab inside' => ["sara\tahmadi@example.com", null],
            'a quoted name' => ['"sara"@example.com', null],
            'a quoted name holding another address' => ['"<ali@evil.example>"@example.com', null],
            'an escaped quote in a quoted name' => ['"sa\"ra"@example.com', null],
            'an address in angle brackets' => ['Sara <sara@example.com>', null],
            'a bracket alone' => ['sara>@example.com', null],
            'nothing' => ['   ', null],
            'too long' => [str_repeat('a', 180) . '@example.com', null],
        ];
    }

    #[DataProvider('addresses')]
    public function testAnAddressIsKeptOneWay(string $typed, ?string $kept): void
    {
        self::assertSame($kept, Email::of($typed));
        self::assertSame($kept === null, Email::problem($typed) !== null);
    }

    public function testWhyIsSaidOfWhatTheFieldIs(): void
    {
        self::assertSame('ایمیل را وارد کنید.', Email::problem(''));
        self::assertSame('ایمیل فرستنده درست نیست؛ آن را مثل name@example.com بنویسید.', Email::problem('shop', 'ایمیل فرستنده'));
        self::assertSame('ایمیل حداکثر 191 کاراکتر است.', Email::problem(str_repeat('a', 180) . '@example.com'));
        self::assertSame('ایمیل درست نیست؛ آن را مثل name@example.com بنویسید.', Email::problem('"<ali@evil.example>"@example.com'), 'PHP\'s own check takes it: the rule refuses it as any address that is none');
    }
}
