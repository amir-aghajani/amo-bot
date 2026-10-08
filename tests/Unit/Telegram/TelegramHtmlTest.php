<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Texts\TelegramHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An admin's wording is read the way Telegram's HTML parser reads it before it is stored: what Telegram
 * would refuse — the customer would get nothing — and what it would silently drop are named, in Persian.
 */
final class TelegramHtmlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function accepted(): iterable
    {
        yield 'plain text' => ['سلام، خوش آمدید'];
        yield 'a stray > and & are text' => ['قیمت > ۱۰۰ & تخفیف'];
        yield 'entities' => ['&lt;b&gt; &amp; &quot; &#128512; &#x1F600;'];
        yield 'the formatting tags' => ['<b>a</b> <strong>b</strong> <i>c</i> <em>d</em> <u>e</u> <ins>f</ins> <s>g</s> <strike>h</strike> <del>i</del>'];
        yield 'any case' => ['<B>پررنگ</B>'];
        yield 'formatting inside formatting' => ['<b>پررنگ <i>و کج</i></b>'];
        yield 'a variable in code' => ['<code>%subscription%</code>'];
        yield 'a link to a variable' => ['<a href="%subscription%">لینک اتصال</a>'];
        yield 'single quotes' => ["<a href='https://t.me/amo'>کانال</a>"];
        yield 'a bare token value' => ['<a href=amo.example>سایت</a>'];
        yield 'formatting in a link' => ['<a href="https://t.me/amo"><b>کانال</b></a>'];
        yield 'spoilers' => ['<tg-spoiler>راز</tg-spoiler> <span class="tg-spoiler">راز</span>'];
        yield 'quotes' => ['<blockquote>نقل‌قول</blockquote><blockquote expandable>بلند</blockquote>'];
        yield 'a code block with its language' => ['<pre><code class="language-php">echo 1;</code></pre>'];
        yield 'an end tag without a name closes the last one' => ['<b>پررنگ</>'];
        yield 'an unknown attribute is ignored' => ['<b class="x">پررنگ</b>'];
        yield 'a premium emoji' => ['<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> سلام'];
        yield 'a premium emoji in bold, a quote, a spoiler' => ['<b>خرید <tg-emoji emoji-id="1">🔥</tg-emoji></b><blockquote><tg-emoji emoji-id="2">✅</tg-emoji></blockquote><tg-spoiler><tg-emoji emoji-id="3">🎁</tg-emoji></tg-spoiler>'];
        yield 'a premium flag, keycap, joined and toned emoji' => ['<tg-emoji emoji-id="4">🇮🇷</tg-emoji><tg-emoji emoji-id="5">1️⃣</tg-emoji><tg-emoji emoji-id="6">👨‍💻</tg-emoji><tg-emoji emoji-id="7">👍🏽</tg-emoji><tg-emoji emoji-id="8">⬅️</tg-emoji>'];
        yield 'a premium emoji written as an entity' => ['<tg-emoji emoji-id="9">&#128512;</tg-emoji>'];
    }

    #[DataProvider('accepted')]
    public function testTelegramTakesIt(string $text): void
    {
        self::assertNull(TelegramHtml::problem($text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'a lone <' => ['اگر حجم < ۱ گیگ شد', '&lt;'];
        yield 'a < at the end' => ['کمتر از <', '&lt;'];
        yield 'a line break tag' => ['خط اول<br>خط دوم', 'Enter'];
        yield 'a self-closed line break' => ['خط اول<br/>خط دوم', 'Enter'];
        yield 'a tag Telegram does not know' => ['<p>بند</p>', 'تگ <p> را نمی‌شناسد'];
        yield 'an open tag' => ['<b>پررنگ', 'تگ <b> بسته نشده است'];
        yield 'a close without an open' => ['پررنگ</b>', 'تگ </b> بسته شده ولی پیش از آن باز نشده است'];
        yield 'a close of another tag' => ['<b>پررنگ</i>', 'تگ </i> بسته شده ولی پیش از آن باز نشده است'];
        yield 'crossed tags' => ['<b><i>پررنگ</b></i>', 'پیش از </b> باید </i> بسته شود'];
        yield 'an unfinished tag' => ['<b پررنگ', 'تگ <b> با > بسته نشده است'];
        yield 'an unfinished end tag' => ['<b>x</b', 'تگ </b> با > بسته نشده است'];
        yield 'a span that is no spoiler' => ['<span>متن</span>', 'tg-spoiler'];
        yield 'an unquoted variable' => ['<a href=%subscription%>لینک</a>', 'داخل "…"'];
        yield 'an attribute without a value' => ['<a href>لینک</a>', 'باید = و مقدار بیاید'];
        yield 'an unclosed quote' => ['<a href="https://t.me>لینک</a>', 'بسته نشده است'];
        yield 'an attribute without a name' => ['<a ="x">لینک</a>', 'نام ویژگی'];
        yield 'formatting in code' => ['<code><b>کد</b></code>', 'داخل <code>'];
        yield 'formatting in pre' => ['<pre><i>کد</i></pre>', 'داخل <pre>'];
        yield 'a link in a link' => ['<a href="x"><a href="y">لینک</a></a>', 'داخل لینک'];
        yield 'code in a link' => ['<a href="x"><code>لینک</code></a>', 'داخل لینک'];
        yield 'a quote in a quote' => ['<blockquote><blockquote>x</blockquote></blockquote>', 'نقل‌قول'];
        yield 'a premium emoji without its id' => ['<tg-emoji>👍</tg-emoji>', 'شناسه عددی'];
        yield 'a premium emoji with an id that is no number' => ['<tg-emoji emoji-id="like">👍</tg-emoji>', 'شناسه عددی'];
        yield 'words in a premium emoji' => ['<tg-emoji emoji-id="5">لایک</tg-emoji>', 'فقط یک ایموجی'];
        yield 'a tag in a premium emoji' => ['<tg-emoji emoji-id="5"><b>👍</b></tg-emoji>', 'فقط یک ایموجی'];
        yield 'an empty premium emoji' => ['<tg-emoji emoji-id="5"></tg-emoji>', 'فقط یک ایموجی'];
        yield 'a digit as a premium emoji' => ['<tg-emoji emoji-id="5">1</tg-emoji>', 'فقط یک ایموجی'];
        yield 'two emoji with a space in one' => ['<tg-emoji emoji-id="5">👍 👍</tg-emoji>', 'فقط یک ایموجی'];
        yield 'a premium emoji in a link' => ['<a href="x"><tg-emoji emoji-id="5">👍</tg-emoji></a>', 'داخل لینک'];
        yield 'a premium emoji in code' => ['<code><tg-emoji emoji-id="5">👍</tg-emoji></code>', 'داخل <code>'];
        yield 'an unclosed premium emoji' => ['<tg-emoji emoji-id="5">👍', 'تگ <tg-emoji> بسته نشده است'];
    }

    #[DataProvider('refused')]
    public function testTheProblemIsNamed(string $text, string $says): void
    {
        self::assertStringContainsString($says, (string) TelegramHtml::problem($text));
    }

    public function testWhatTheCustomerSeesIsCountedNotTheMarkup(): void
    {
        // As Telegram counts against its limits: UTF-16 units — the letters one each, the emoji (beyond the basic plane) two.
        self::assertSame(9, TelegramHtml::visibleLength('<b>سلام</b> <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> &lt;'));
        self::assertSame(2, TelegramHtml::visibleLength('⚠️'), 'a sign and its emoji form, one unit each');
    }
}
