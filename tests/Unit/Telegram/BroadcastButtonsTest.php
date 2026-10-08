<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Core\Exceptions\ValidationException;
use App\Modules\Telegram\Broadcasts\BroadcastService;
use App\Modules\Telegram\Keyboard\KeyboardLayouts;
use App\Modules\Telegram\Messages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The link buttons a bot admin types under a broadcast: a row a line, buttons on a line apart by «|», each «text - link»
 * (any dash), the link http(s) or tg:// and a label as long as a keyboard's — at most BUTTON_ROWS rows of
 * BUTTONS_PER_ROW; a line that is not right is said back as it was typed.
 */
final class BroadcastButtonsTest extends TestCase
{
    public function testRowsAreTypedALineAndButtonsApartByABar(): void
    {
        self::assertSame(
            [
                [['text' => 'کانال', 'url' => 'https://t.me/news'], ['text' => 'سایت', 'url' => 'http://example.com/a?b=1']],
                [['text' => 'پشتیبانی', 'url' => 'tg://resolve?domain=support']],
            ],
            BroadcastService::parseButtons("  کانال - https://t.me/news |سایت – http://example.com/a?b=1\r\n\n پشتیبانی — tg://resolve?domain=support \n"),
            'any dash; blank lines and the spaces around a button do not count',
        );
    }

    public function testAsManyAsTheCardTakesAndNotOneMore(): void
    {
        $line = static fn(int $buttons): string => implode(' | ', array_map(static fn(int $i): string => "دکمه {$i} - https://t.me/b{$i}", range(1, $buttons)));
        $full = implode("\n", array_fill(0, BroadcastService::BUTTON_ROWS, $line(BroadcastService::BUTTONS_PER_ROW)));

        self::assertCount(BroadcastService::BUTTON_ROWS, BroadcastService::parseButtons($full));

        $tooMany = Messages::fill(Messages::BROADCAST_BUTTONS_TOO_MANY, ['rows' => BroadcastService::BUTTON_ROWS, 'per_row' => BroadcastService::BUTTONS_PER_ROW]);
        self::assertSame($tooMany, self::refusal($full . "\n" . $line(1)), 'a row more');
        self::assertSame($tooMany, self::refusal($line(BroadcastService::BUTTONS_PER_ROW + 1)), 'a button more on a row');
        self::assertSame($tooMany, self::refusal(" \n "), 'none at all');
    }

    #[DataProvider('wrongButtons')]
    public function testAButtonThatIsNotRightIsSaidBackAsTyped(string $button): void
    {
        self::assertSame(
            Messages::fill(Messages::BROADCAST_BUTTONS_INVALID, ['line' => htmlspecialchars(mb_substr($button, 0, 60)), 'max' => KeyboardLayouts::LABEL_MAX]),
            self::refusal("کانال - https://t.me/news\n" . $button . ' | سایت - https://example.com'),
        );
    }

    public function testTheRefusalNamesEveryLinkAButtonTakes(): void
    {
        $refusal = self::refusal('کانال بدون لینک');

        foreach (['http://', 'https://', 'tg://'] as $link) {
            self::assertStringContainsString($link, $refusal, 'what is taken is what is said');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function wrongButtons(): iterable
    {
        yield 'no link' => ['کانال بدون لینک'];
        yield 'no text' => ['https://t.me/news'];
        yield 'another kind of link' => ['فایل - ftp://example.com/list.txt'];
        yield 'a script for a link' => ['کلیک - javascript:alert(1)'];
        yield 'a web link that is no address' => ['سایت - http://:80'];
        yield 'a label longer than a keyboard takes' => [str_repeat('ب', KeyboardLayouts::LABEL_MAX + 1) . ' - https://t.me/news'];
        yield 'markup, said back harmless' => ['<b>کانال</b>'];
    }

    private static function refusal(string $typed): string
    {
        try {
            BroadcastService::parseButtons($typed);
        } catch (ValidationException $e) {
            self::assertSame(['buttons'], array_keys($e->errors()));

            return $e->errors()['buttons'][0];
        }

        self::fail('The buttons were taken.');
    }
}
