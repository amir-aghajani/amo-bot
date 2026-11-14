<?php

declare(strict_types=1);

namespace Tests\Unit\Imports\Faoxima;

use App\Modules\Imports\Migrators\Faoxima\Cell;
use PHPUnit\Framework\TestCase;

/** Faoxima's values read: its placeholders as nothing, numbers kept as text, unix times, comma-separated lists. */
final class CellTest extends TestCase
{
    public function testTextWithoutFaoximasPlaceholders(): void
    {
        self::assertSame(['a b', null, null, null, null], [Cell::text(' a b '), Cell::text(''), Cell::text('none'), Cell::text('NULL'), Cell::text(null)]);
    }

    public function testNumbersInTheirPlainestDigits(): void
    {
        self::assertSame(
            ['10', '12.5', '10000', '10000', '-3', '0', '30', null, null, null],
            [Cell::number('10'), Cell::number('12.50'), Cell::number('10,000'), Cell::number('۱۰٬۰۰۰'), Cell::number('-3'), Cell::number('-0'), Cell::number('30.000'), Cell::number('abc'), Cell::number('1e5'), Cell::number(null)],
        );
        self::assertSame([150000, 0, 12], [Cell::whole('150000'), Cell::whole('none'), Cell::whole('12.9')]);
        self::assertSame(['120000.00', '-30000.00', '0.00'], [Cell::amount('120000'), Cell::amount('-30000'), Cell::amount('')]);
    }

    public function testUnixTimesAndLists(): void
    {
        self::assertEquals(new \DateTimeImmutable('@1700000000'), Cell::moment('1700000000'));
        self::assertSame([null, null, null], [Cell::moment('0'), Cell::moment('none'), Cell::moment('1403/01/01')]);
        self::assertSame(['آلمان ۱', 'هلند'], Cell::list(' آلمان ۱ ,هلند,,آلمان ۱'));
        self::assertSame([], Cell::list(null));
    }
}
