<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

/** Words cut to the room the shop gives them, by their characters — how a ticket's subject and a button's label are cut. */
final class TextTest extends TestCase
{
    public function testWhatFitsStaysAsItIs(): void
    {
        self::assertSame('سلام', Text::fit('سلام', 4));
        self::assertSame('', Text::fit('', 0));
    }

    public function testWhatDoesNotIsCutWithAnEllipsisWithinItsRoom(): void
    {
        self::assertSame('سلا…', Text::fit('سلام دنیا', 4));
        self::assertSame('سلام…', Text::fit('سلام دنیا', 6), 'a space it ends at is not kept');
        self::assertSame('…', Text::fit('سلام', 1));
        self::assertSame(4, mb_strlen(Text::fit(str_repeat('ب', 50), 4)));
    }

    public function testAtAWordItEndsAtTheLastSpaceInTheSecondHalf(): void
    {
        self::assertSame('یک دو سه…', Text::fit('یک دو سه چهار پنج', 12, atWord: true));
        self::assertSame('یک دو سه چه…', Text::fit('یک دو سه چهار پنج', 12), 'cut where the room ends otherwise');
        self::assertSame('یکدوسهچهارپ…', Text::fit('یکدوسهچهارپنج شش', 12, atWord: true), 'no space in the second half: cut where the room ends');
    }

    /** A Telegram name made to look empty is typed with spaces, zero-width characters or fillers: it shows nothing. */
    public function testTextOfInvisibleCharactersAloneIsBlank(): void
    {
        foreach (['', ' ', "\u{00A0}", "\u{200B}\u{200C}\u{200D}", "\u{200F}\u{2063}", "\u{FEFF}\u{2060}", "\u{3164}", "\u{115F}\u{1160}", "\u{FFA0}", "\u{2800}", "\u{034F}", "\u{17B5}", "\t\n\u{2009}\u{3000}"] as $invisible) {
            self::assertTrue(Text::blank($invisible), (string) json_encode($invisible));
        }
        foreach (['علی', "\u{00A0}Ali", "نیما\u{200C}", '.', '😀'] as $visible) {
            self::assertFalse(Text::blank($visible), $visible);
        }
    }
}
