<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Texts\EntityHtml;
use App\Modules\Telegram\Texts\TelegramHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A message as Telegram hands it over — its text and entities, counted in UTF-16 code units — written as the Telegram
 * HTML the bot's texts are, premium emoji as <tg-emoji>; and the premium emoji it carries.
 */
final class EntityHtmlTest extends TestCase
{
    /** @return iterable<string, array{string, list<array<string, mixed>>, string}> */
    public static function messages(): iterable
    {
        yield 'plain text, escaped' => ['a < b & c > d', [], 'a &lt; b &amp; c &gt; d'];
        yield 'a premium emoji after Persian, counted in UTF-16 (🔥 is two units)' => [
            'سلام 🔥 دنیا',
            [['type' => 'bold', 'offset' => 0, 'length' => 4], ['type' => 'custom_emoji', 'offset' => 5, 'length' => 2, 'custom_emoji_id' => '5368324170671202286']],
            '<b>سلام</b> <tg-emoji emoji-id="5368324170671202286">🔥</tg-emoji> دنیا',
        ];
        yield 'nested: a premium emoji and italic inside bold' => [
            '🔥 خرید',
            [['type' => 'bold', 'offset' => 0, 'length' => 7], ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '1'], ['type' => 'italic', 'offset' => 3, 'length' => 4]],
            '<b><tg-emoji emoji-id="1">🔥</tg-emoji> <i>خرید</i></b>',
        ];
        yield 'entities in any order' => [
            '🔥 خرید',
            [['type' => 'italic', 'offset' => 3, 'length' => 4], ['type' => 'custom_emoji', 'offset' => 0, 'length' => 2, 'custom_emoji_id' => '1'], ['type' => 'bold', 'offset' => 0, 'length' => 7]],
            '<b><tg-emoji emoji-id="1">🔥</tg-emoji> <i>خرید</i></b>',
        ];
        yield 'a link, its address escaped' => ['لینک', [['type' => 'text_link', 'offset' => 0, 'length' => 4, 'url' => 'https://x.y/?a=1&b="2"']], '<a href="https://x.y/?a=1&amp;b=&quot;2&quot;">لینک</a>'];
        yield 'a mention of an account' => ['علی', [['type' => 'text_mention', 'offset' => 0, 'length' => 3, 'user' => ['id' => 42, 'is_bot' => false, 'first_name' => 'علی']]], '<a href="tg://user?id=42">علی</a>'];
        yield 'a code block with its language' => ['echo 1;', [['type' => 'pre', 'offset' => 0, 'length' => 7, 'language' => 'php']], '<pre><code class="language-php">echo 1;</code></pre>'];
        yield 'code, strikethrough, underline, a spoiler, quotes' => [
            'a b c d e f',
            [['type' => 'code', 'offset' => 0, 'length' => 1], ['type' => 'strikethrough', 'offset' => 2, 'length' => 1], ['type' => 'underline', 'offset' => 4, 'length' => 1], ['type' => 'spoiler', 'offset' => 6, 'length' => 1], ['type' => 'blockquote', 'offset' => 8, 'length' => 1], ['type' => 'expandable_blockquote', 'offset' => 10, 'length' => 1]],
            '<code>a</code> <s>b</s> <u>c</u> <tg-spoiler>d</tg-spoiler> <blockquote>e</blockquote> <blockquote expandable>f</blockquote>',
        ];
        yield 'what Telegram finds by itself stays text, and so does a variable' => [
            '@amo https://t.me #tag %client%',
            [['type' => 'mention', 'offset' => 0, 'length' => 4], ['type' => 'url', 'offset' => 5, 'length' => 12], ['type' => 'hashtag', 'offset' => 18, 'length' => 4]],
            '@amo https://t.me #tag %client%',
        ];
        yield 'one that would cross another is left out' => ['abcdef', [['type' => 'bold', 'offset' => 0, 'length' => 4], ['type' => 'italic', 'offset' => 2, 'length' => 4]], '<b>abcd</b>ef'];
        yield 'one past the end is left out' => ['ab', [['type' => 'bold', 'offset' => 1, 'length' => 5]], 'ab'];
    }

    /** @param list<array<string, mixed>> $entities */
    #[DataProvider('messages')]
    public function testAMessageIsWrittenAsTheBotsTextsAre(string $text, array $entities, string $html): void
    {
        self::assertSame($html, EntityHtml::of($text, $entities));
        self::assertNull(TelegramHtml::problem($html), 'and the bot can send it as it is');
    }

    public function testThePremiumEmojiAMessageCarriesWithThePlainOnesTheyStandFor(): void
    {
        $text = 'سلام 🔥 و 👨‍💻 و 🔥';
        $entities = [
            ['type' => 'custom_emoji', 'offset' => 5, 'length' => 2, 'custom_emoji_id' => '111'],
            ['type' => 'bold', 'offset' => 0, 'length' => 4],
            ['type' => 'custom_emoji', 'offset' => 10, 'length' => 5, 'custom_emoji_id' => '222'],
            ['type' => 'custom_emoji', 'offset' => 18, 'length' => 2, 'custom_emoji_id' => '111'],
            ['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => 'not-a-number'],
        ];

        self::assertSame([['id' => '111', 'emoji' => '🔥'], ['id' => '222', 'emoji' => '👨‍💻']], EntityHtml::premiumEmoji($text, $entities), 'each once, in the order the message has them');
    }
}
