<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Keyboard\InlineKeyboard;
use PHPUnit\Framework\TestCase;

/**
 * Buttons laid out by how many share each one's row — a plan list, a plan's locations: consecutive ones of one number
 * fill rows of that many, one of another number starts its own, and a row reads right to left (Telegram lays it out left
 * to right) — as a grid of so many a row does.
 */
final class InlineKeyboardFlowTest extends TestCase
{
    public function testConsecutiveButtonsOfOneWidthShareRows(): void
    {
        self::assertSame([['b', 'a'], ['c'], ['e', 'd'], ['f']], self::flow([['a', 2], ['b', 2], ['c', 1], ['d', 2], ['e', 2], ['f', 2]]), 'a third of a row of two starts the next');
        self::assertSame([['c', 'b', 'a'], ['e', 'd']], self::flow([['a', 3], ['b', 3], ['c', 3], ['d', 3], ['e', 3]]), 'the last row as full as it gets');
        self::assertSame([['a'], ['c', 'b'], ['f', 'e', 'd']], self::flow([['a', 1], ['b', 2], ['c', 2], ['d', 3], ['e', 3], ['f', 3]]));
        self::assertSame([['a'], ['b'], ['c']], self::flow([['a', 1], ['b', 1], ['c', 1]]), 'a row each, as every list was');
        self::assertSame([['a'], ['b']], self::flow([['a', 2], ['b', 3]]), 'two of different widths share nothing');
        self::assertSame([['b', 'a'], ['c']], self::flow([['a', 7], ['b', 3], ['c', 0]]), 'held to one to three');
        self::assertSame([], self::flow([]));
    }

    public function testAGridReadsRightToLeftToo(): void
    {
        $buttons = array_map(static fn(string $text): array => InlineKeyboard::callback($text, $text), ['a', 'b', 'c', 'd', 'e']);
        $rows = InlineKeyboard::make()->grid($buttons, 2)->build()['inline_keyboard'];

        self::assertSame([['b', 'a'], ['d', 'c'], ['e']], array_map(static fn(array $row): array => array_column($row, 'text'), $rows), 'the amounts of a top-up, the first on the right');
    }

    /**
     * @param list<array{string, int}> $buttons Each button's text and how many share its row
     * @return list<list<string>> The rows' texts, as Telegram reads them: left to right
     */
    private static function flow(array $buttons): array
    {
        $keyboard = InlineKeyboard::make()->flow(array_map(static fn(array $button): array => [InlineKeyboard::callback($button[0], $button[0]), $button[1]], $buttons))->build();

        return array_map(static fn(array $row): array => array_column($row, 'text'), $keyboard['inline_keyboard']);
    }
}
