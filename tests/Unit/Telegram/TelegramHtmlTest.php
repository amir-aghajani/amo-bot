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
        yield 'entities without their «;», as Telegram reads them' => ['&lt;b&gt &amp &#65'];
        yield 'an «&» of the words' => ['R&D و AT&T و &'];
        yield 'the formatting tags' => ['<b>a</b> <strong>b</strong> <i>c</i> <em>d</em> <u>e</u> <ins>f</ins> <s>g</s> <strike>h</strike> <del>i</del>'];
        // Telegram reads a tag's name in any case (tdlib lowers it), never an attribute's.
        yield 'a tag name in any case' => ['<B>پررنگ</B> <A href="https://t.me/amo">کانال</A>'];
        yield 'an attribute a tag does not read, in any case' => ['<b CLASS="x">پررنگ</b>'];
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
        // As the Bot API writes a date: its Unix time, a format of its control characters, or none — the words as they are.
        yield 'a date and time' => ['<tg-time unix="1647531900" format="wDT">۲۲:۴۵ فردا</tg-time>'];
        yield 'each format Telegram reads' => ['<tg-time unix="1647531900" format="r">x</tg-time><tg-time unix="1647531900" format="t">x</tg-time><tg-time unix="1647531900" format="wd">x</tg-time><tg-time unix="1647531900" format="DT">x</tg-time><tg-time unix="1647531900" format="">x</tg-time>'];
        yield 'a date without a format, its value bare' => ['<tg-time unix=1647531900>۲۲:۴۵ فردا</tg-time> <tg-time unix="1" format=wdt>x</tg-time>'];
        yield 'a date in bold, in a quote, with bold inside' => ['<b><tg-time unix="1647531900">x</tg-time></b><blockquote><tg-time unix="1647531900"><i>x</i></tg-time></blockquote>'];
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
        yield 'a premium emoji whose id ends in a line break' => ["<tg-emoji emoji-id=\"5\n\">👍</tg-emoji>", 'شناسه عددی'];
        yield 'words in a premium emoji' => ['<tg-emoji emoji-id="5">لایک</tg-emoji>', 'فقط یک ایموجی'];
        yield 'a tag in a premium emoji' => ['<tg-emoji emoji-id="5"><b>👍</b></tg-emoji>', 'فقط یک ایموجی'];
        yield 'an empty premium emoji' => ['<tg-emoji emoji-id="5"></tg-emoji>', 'فقط یک ایموجی'];
        yield 'a digit as a premium emoji' => ['<tg-emoji emoji-id="5">1</tg-emoji>', 'فقط یک ایموجی'];
        yield 'two emoji with a space in one' => ['<tg-emoji emoji-id="5">👍 👍</tg-emoji>', 'فقط یک ایموجی'];
        yield 'a premium emoji in a link' => ['<a href="x"><tg-emoji emoji-id="5">👍</tg-emoji></a>', 'داخل لینک'];
        yield 'a premium emoji in code' => ['<code><tg-emoji emoji-id="5">👍</tg-emoji></code>', 'داخل <code>'];
        yield 'an unclosed premium emoji' => ['<tg-emoji emoji-id="5">👍', 'تگ <tg-emoji> بسته نشده است'];
        // Read by its exact name: a link in capitals would lose its address, a spoiler or a premium emoji be refused.
        yield 'a link\'s address in capitals' => ['<a HREF="https://t.me/amo">کانال</a>', 'نام «HREF» را با حروف کوچک بنویسید: href'];
        yield 'a spoiler\'s class in capitals' => ['<span CLASS="tg-spoiler">راز</span>', 'class'];
        yield 'a premium emoji\'s id in capitals' => ['<tg-emoji Emoji-Id="5">👍</tg-emoji>', 'emoji-id'];
        yield 'an expandable quote in capitals' => ['<blockquote EXPANDABLE>بلند</blockquote>', 'expandable'];
        yield 'a date\'s time in capitals' => ['<tg-time UNIX="1647531900">x</tg-time>', 'نام «UNIX» را با حروف کوچک بنویسید: unix'];
        yield 'a date\'s format in capitals' => ['<tg-time unix="1647531900" Format="t">x</tg-time>', 'format'];
        // A date: its Unix time, a format of Telegram's, and words.
        yield 'a date without its time' => ['<tg-time format="t">x</tg-time>', 'ثانیه‌های یونیکس'];
        yield 'a date whose time is no number' => ['<tg-time unix="فردا">x</tg-time>', 'ثانیه‌های یونیکس'];
        yield 'a date before 1970' => ['<tg-time unix="-5">x</tg-time>', 'ثانیه‌های یونیکس'];
        yield 'a date at zero' => ['<tg-time unix="0">x</tg-time>', 'ثانیه‌های یونیکس'];
        yield 'a date past what Telegram holds' => ['<tg-time unix="2147483648">x</tg-time>', 'ثانیه‌های یونیکس'];
        yield 'a date whose time ends in a line break' => ["<tg-time unix=\"1647531900\n\">x</tg-time>", 'ثانیه‌های یونیکس'];
        yield 'a format that ends in a line break' => ["<tg-time unix=\"1647531900\" format=\"t\n\">x</tg-time>", 'مقدار format'];
        yield 'a format Telegram does not read' => ['<tg-time unix="1647531900" format="Y-m-d">x</tg-time>', 'مقدار format'];
        yield 'relative time with more' => ['<tg-time unix="1647531900" format="rT">x</tg-time>', 'مقدار format'];
        yield 'a format out of order' => ['<tg-time unix="1647531900" format="Tw">x</tg-time>', 'مقدار format'];
        yield 'a bare format with capitals, read in lower case' => ['<tg-time unix="1647531900" format=wDT>x</tg-time>', 'داخل "…"'];
        yield 'an empty date' => ['<tg-time unix="1647531900" format="t"></tg-time>', 'داخل <tg-time> متنی بنویسید'];
        yield 'a date of nothing but a tag' => ['<tg-time unix="1647531900"><b> </b></tg-time>', 'داخل <tg-time> متنی بنویسید'];
        yield 'an unclosed date' => ['<tg-time unix="1647531900">x', 'تگ <tg-time> بسته نشده است'];
        // What holds no other entity: a link, a date.
        yield 'a date in a link' => ['<a href="x"><tg-time unix="1">x</tg-time></a>', 'داخل لینک (<a>) تگ <tg-time>'];
        yield 'a link in a date' => ['<tg-time unix="1"><a href="x">x</a></tg-time>', 'داخل زمان (<tg-time>) تگ <a>'];
        yield 'a premium emoji in a date' => ['<tg-time unix="1"><tg-emoji emoji-id="5">👍</tg-emoji></tg-time>', 'داخل زمان (<tg-time>) تگ <tg-emoji>'];
        yield 'a date in a date' => ['<tg-time unix="1"><tg-time unix="2">x</tg-time></tg-time>', 'داخل زمان'];
        yield 'code in a date' => ['<tg-time unix="1"><code>x</code></tg-time>', 'داخل زمان'];
        yield 'a date in code' => ['<code><tg-time unix="1">x</tg-time></code>', 'داخل <code>'];
        // Shown as typed: only four names and the numbers Telegram reads.
        yield 'a named entity Telegram does not know' => ['قیمت&nbsp;ویژه', 'تلگرام «&nbsp;» را نمی‌شناسد'];
        yield 'another' => ['&copy; فروشگاه', '«&copy;»'];
        yield 'a known name in capitals' => ['&LT;b&GT;', '«&LT;»'];
        yield 'a hex number after a capital X' => ['&#X1F600;', '«&#X1F600;»'];
        yield 'zero' => ['&#0;', '«&#0;»'];
        yield 'past the last character' => ['&#1114111;', '«&#1114111;»'];
        yield 'too long to be a number' => ['&#00000000065;', '«&#00000000065;»'];
        yield 'half an emoji' => ['&#55357;&#56832;', 'نیمی از یک نویسه'];
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
        self::assertSame(7, TelegramHtml::visibleLength('&nbsp;a'), 'an entity Telegram does not read is shown as typed: counted so');
        self::assertSame(3, TelegramHtml::visibleLength('&lt&#65&#x42;'), 'one it reads is one character, its «;» or not');
        self::assertSame(10, TelegramHtml::visibleLength('<tg-time unix="1647531900" format="wDT">۲۲:۴۵ فردا</tg-time>'), "a date's words, as the message carries them");
    }

    public function testADatesWordsAreWhatIsReadOfIt(): void
    {
        $date = 'تمدید تا <tg-time unix="1647531900" format="D">۲۶ اسفند</tg-time> &amp; بعد';

        self::assertSame('تمدید تا ۲۶ اسفند & بعد', TelegramHtml::visible($date));
        self::assertSame('<tg-time unix="1647531900" format="D">۲۶ اسفند</tg-time> & بعد', TelegramHtml::decode('<tg-time unix="1647531900" format="D">۲۶ اسفند</tg-time> &amp; بعد'), 'the plain-text fallback reads the entities and leaves the tags');
    }
}
