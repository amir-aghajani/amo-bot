<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Traffic;
use PHPUnit\Framework\TestCase;

/** Traffic is bytes everywhere; the gigabytes an admin types become them one way — binary, as the panels count. */
final class TrafficTest extends TestCase
{
    public function testGigabytesAsTypedAreWholeBytes(): void
    {
        self::assertSame(1024 ** 3, Traffic::GIGABYTE);
        self::assertSame(30 * Traffic::GIGABYTE, Traffic::bytesOfGb(30));
        self::assertSame(1_610_612_736, Traffic::bytesOfGb('1.5'), 'a fraction, as a form sends it');
        self::assertSame(512 * Traffic::MEGABYTE, Traffic::bytesOfGb(0.5));
        self::assertSame(0, Traffic::bytesOfGb(0), 'unlimited stays 0');
    }
}
