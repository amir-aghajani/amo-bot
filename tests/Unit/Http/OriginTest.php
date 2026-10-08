<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Core\Http\Origin;
use PHPUnit\Framework\TestCase;

/**
 * An origin in the one form CORS compares it in — `scheme://host[:port]`, http or https, lower case, the scheme's own
 * port left out — whether a browser sent it or a website's owner typed it; anything that is more than an origin, or
 * no http(s) address at all, is none.
 */
final class OriginTest extends TestCase
{
    public function testAnOriginIsReadAsBrowsersSendIt(): void
    {
        self::assertSame('https://shop.example', Origin::normalize('https://shop.example'));
        self::assertSame('https://shop.example', Origin::normalize(' HTTPS://Shop.Example/ '), 'case, spaces and a trailing slash');
        self::assertSame('https://shop.example', Origin::normalize('https://shop.example:443'), "the scheme's own port");
        self::assertSame('http://localhost:3000', Origin::normalize('http://localhost:3000'));
        self::assertSame('http://[::1]:8080', Origin::normalize('http://[::1]:8080'));

        foreach (['https://shop.example/app', 'https://shop.example?x=1', 'https://shop.example#top', 'localhost:3000', 'ftp://shop.example', 'https://user:pass@shop.example', 'null', '', 'https://shop example.com'] as $value) {
            self::assertNull(Origin::normalize($value), $value);
        }
    }

    public function testAnAddressHasTheOriginItIsOn(): void
    {
        self::assertSame('https://shop.example', Origin::of('https://Shop.Example/auth/telegram?next=%2Fme'));
        self::assertSame('http://localhost:3000', Origin::of('http://localhost:3000/callback'));
        self::assertNull(Origin::of('javascript:alert(1)'));
        self::assertNull(Origin::of('/auth/telegram'));
        self::assertNull(Origin::of('https://' . str_repeat('a', 2050) . '.example/'));
    }
}
