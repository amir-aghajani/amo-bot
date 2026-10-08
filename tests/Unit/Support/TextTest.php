<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Text;
use PHPUnit\Framework\TestCase;

/** Words cut to the room the shop gives them, by their characters — the one way a ticket's subject and a button's label are cut. */
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
}
