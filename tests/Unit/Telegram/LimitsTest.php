<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Api\Limits;
use PHPUnit\Framework\TestCase;

/**
 * Telegram counts what it takes in UTF-16 code units: a Persian or Latin letter is one, an emoji beyond the basic plane
 * two — so a text of 4096 characters with emoji in it may be past a message's 4096. A text cut to Telegram's room is cut
 * in those units, never through the middle of a character.
 */
final class LimitsTest extends TestCase
{
    public function testALengthIsTelegramsUtf16Units(): void
    {
        self::assertSame(0, Limits::length(''));
        self::assertSame(4, Limits::length('سلام'));
        self::assertSame(2, Limits::length('😀'), 'beyond the basic plane: two units, one code point');
        self::assertSame(1, Limits::length('☺'), 'a sign of the basic plane: one');
        self::assertSame(7, Limits::length('ok 😀 ب'));
    }

    public function testACutTakesWhatFitsAndNeverHalfACharacter(): void
    {
        self::assertSame('سلام', Limits::cut('سلام', 4), 'what fits is kept whole');
        self::assertSame('سل', Limits::cut('سلام', 2));
        self::assertSame('😀😀', Limits::cut('😀😀😀', 5), 'the third emoji would take the sixth unit too: left out whole');
        self::assertSame('a😀', Limits::cut('a😀b', 3));
        self::assertSame('a', Limits::cut('a😀b', 2));
        self::assertSame('', Limits::cut('😀', 1));
        self::assertSame('', Limits::cut('abc', 0));

        $cut = Limits::cut(str_repeat('👍', 150), Limits::POPUP);
        self::assertSame([Limits::POPUP, 100], [Limits::length($cut), mb_strlen($cut)], "a popup's 200 units are 100 emoji");
        self::assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    public function testAFitIsWhatFitsElseCutShortWithAnEllipsisWithinItsUnits(): void
    {
        self::assertSame('سلام', Limits::fit('سلام', 4));
        self::assertSame('سلا…', Limits::fit('سلام دنیا', 4));
        self::assertSame('😀…', Limits::fit('😀😀😀', 4), 'two units of emoji and the ellipsis: four');
        self::assertSame('…', Limits::fit('😀😀', 2), 'an emoji is never cut in two');

        $fitted = Limits::fit(str_repeat('پیام 👍 ', 300), Limits::CAPTION);
        self::assertLessThanOrEqual(Limits::CAPTION, Limits::length($fitted), 'what fits a caption by its characters would not');
        self::assertStringEndsWith('…', $fitted);
    }
}
