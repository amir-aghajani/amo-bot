<?php

declare(strict_types=1);

namespace Tests\Unit\Telegram;

use App\Modules\Telegram\Texts\WebText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The bot's words — Telegram HTML — in a website's two forms: plain text (the tags dropped, the entities read, a premium
 * emoji its plain emoji) and safe HTML — Telegram's formatting the web has, a spoiler as a span, a link only to an
 * http(s) address, a premium emoji its plain emoji, line breaks as <br> but inside a <pre>; every other tag its text,
 * every attribute but a link's href gone, every text escaped, every element closed in order — whatever the source holds.
 */
final class WebTextTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> source, plain text, safe HTML */
    public static function texts(): iterable
    {
        yield 'a notice as the bot words it' => [
            "✅ سرویس با موفقیت ایجاد شد\n\n👤 نام کاربری سرویس: <code>ali_1</code>\n🔗 https://x.test/sub?a=1&amp;b=2",
            "✅ سرویس با موفقیت ایجاد شد\n\n👤 نام کاربری سرویس: ali_1\n🔗 https://x.test/sub?a=1&b=2",
            '✅ سرویس با موفقیت ایجاد شد<br><br>👤 نام کاربری سرویس: <code>ali_1</code><br>🔗 https://x.test/sub?a=1&amp;b=2',
        ];
        yield "Telegram's formatting, kept" => [
            '<b>b</b><strong>s</strong><i>i</i><em>e</em><u>u</u><ins>n</ins><s>s</s><strike>k</strike><del>d</del><code>c</code><blockquote>q</blockquote>',
            'bsieunskdcq',
            '<b>b</b><strong>s</strong><i>i</i><em>e</em><u>u</u><ins>n</ins><s>s</s><strike>k</strike><del>d</del><code>c</code><blockquote>q</blockquote>',
        ];
        yield 'a spoiler either way Telegram writes one, a span of any other class its text' => [
            '<tg-spoiler>a</tg-spoiler> <span class="tg-spoiler">b</span> <span class="x" style="color:red">c</span>',
            'a b c',
            '<span class="tg-spoiler">a</span> <span class="tg-spoiler">b</span> c',
        ];
        yield 'a premium emoji, its plain emoji' => ['<tg-emoji emoji-id="5368324170671202286">👍</tg-emoji> آماده', '👍 آماده', '👍 آماده'];
        yield 'a code block keeps its lines, and loses its language' => ["<pre><code class=\"language-php\">echo 1;\necho 2;</code></pre>\nبعد", "echo 1;\necho 2;\nبعد", "<pre><code>echo 1;\necho 2;</code></pre><br>بعد"];
        yield 'an expandable quote, a quote' => ['<blockquote expandable>q</blockquote>', 'q', '<blockquote>q</blockquote>'];
        yield 'a link to a web address, its other attributes gone' => ['<a href="https://shop.example/help" target="_blank" onmouseover="steal()">راهنما</a>', 'راهنما', '<a href="https://shop.example/help">راهنما</a>'];
        yield 'a bare t.me address, made https' => ['<a href="t.me/amo_shop">کانال</a>', 'کانال', '<a href="https://t.me/amo_shop">کانال</a>'];
        yield 'a link escaped where it could break out' => ['<a href="https://x.test/&quot;&gt;&lt;script&gt;">x</a>', 'x', '<a href="https://x.test/&quot;&gt;&lt;script&gt;">x</a>'];
        yield 'what is text stays text' => ['&lt;script&gt; "a" & \'b\' < c', '<script> "a" & \'b\' < c', '&lt;script&gt; &quot;a&quot; &amp; &apos;b&apos; &lt; c'];
        yield 'the outer white space' => ["\n\n  سلام  \n", 'سلام', 'سلام'];
    }

    /** @return iterable<string, array{string, string}> source, safe HTML */
    public static function hostile(): iterable
    {
        yield 'a script' => ['<script>alert(1)</script>', 'alert(1)'];
        yield 'a script in an svg, a handler on it' => ['<svg onload="alert(1)"><script>alert(2)</script></svg>', 'alert(2)'];
        yield 'a picture that runs a handler' => ['<img src=x onerror=alert(1)>', ''];
        yield 'a handler on a kept tag' => ['<b onclick="alert(1)" style="x">bold</b>', '<b>bold</b>'];
        yield 'a javascript: link' => ['<a href="javascript:alert(1)">x</a>', 'x'];
        yield 'one spaced and cased' => ['<a href=" JavaScript:alert(1)">x</a>', 'x'];
        yield 'one broken by a line' => ["<a href=\"java\nscript:alert(1)\">x</a>", 'x'];
        yield 'a data: link' => ['<a href="data:text/html,<script>alert(1)</script>">x</a>', 'x'];
        yield 'a protocol-relative link' => ['<a href="//evil.test">x</a>', 'x'];
        yield 'an address with no host' => ['<a href="https:///evil.test">x</a>', 'x'];
        yield "a Telegram account's mention" => ['<a href="tg://user?id=42">علی</a>', 'علی'];
        yield 'a link inside a link' => ['<a href="https://a.test">x <a href="https://b.test">y</a></a>', '<a href="https://a.test">x y</a>'];
        yield 'an iframe' => ['<iframe src="https://evil.test">متن</iframe>', 'متن'];
        yield 'a style and a comment' => ['<style>*{}</style><!-- x --><b>b</b>', '*{}&lt;!-- x --&gt;<b>b</b>'];
        yield 'tags crossed' => ['<b><i>x</b>y</i>', '<b><i>x</i></b>y'];
        yield 'a tag never closed' => ['<b>bold <i>both', '<b>bold <i>both</i></b>'];
        yield 'an end tag nothing opened' => ['</b>text</i>', 'text'];
        yield 'a tag cut short' => ['text <b class="x', 'text &lt;b class=&quot;x'];
        yield 'a «<» that opens nothing' => ['a < b <3 c', 'a &lt; b &lt;3 c'];
        yield 'garbage inside a tag' => ['a <b <3 "x> c', 'a <b> c</b>'];
        yield 'self-closed' => ['<b/>x<br/>y', 'xy'];
        yield 'an unquoted attribute' => ['<a href=https://x.test/>u</a>', '<a href="https://x.test/">u</a>'];
    }

    #[DataProvider('texts')]
    public function testTheBotsWordsInTheWebsitesTwoForms(string $source, string $plain, string $html): void
    {
        self::assertSame($plain, WebText::plain($source));
        self::assertSame($html, WebText::html($source));
    }

    #[DataProvider('hostile')]
    public function testWhatIsNoTelegramHtmlComesOutHarmless(string $source, string $html): void
    {
        $safe = WebText::html($source);

        self::assertSame($html, $safe);
        self::assertSame(1, preg_match('~^(?:[^<>]|<(?:/?(?:b|strong|i|em|u|ins|s|strike|del|code|pre|blockquote|span)|span class="tg-spoiler"|a href="https?://[^"<>]+"|/a|br)>)*$~u', $safe), "only the kept tags: {$safe}");
        self::assertStringNotContainsStringIgnoringCase('javascript', $safe);
    }
}
