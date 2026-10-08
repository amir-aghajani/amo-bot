<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Modules\Telegram\Api\Limits;

/**
 * Whether Telegram will take a text in HTML parse mode — an admin's wording is checked here before it is
 * stored, so a customer never gets "can't parse entities" instead of their service. It reads the text the
 * way Telegram's parser does: only the tags the Bot API knows, attributes as `name="value"` (a bare token
 * may hold only letters, digits, «.» and «-»), every tag closed in order, a «<» that opens no tag refused;
 * a stray «>» or «&» is text. It also refuses what Telegram would drop without a word — formatting inside
 * <code>/<pre>, a link inside a link, a quote inside a quote — so the customer sees what the admin wrote. A
 * premium emoji is `<tg-emoji emoji-id="…">😀</tg-emoji>`: its numeric id, and the plain emoji it stands for —
 * nothing else — inside (what is shown where the premium one cannot be).
 */
final class TelegramHtml
{
    /** The tags Telegram reads; `span` only as a spoiler. */
    private const TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'tg-spoiler', 'span', 'a', 'code', 'pre', 'blockquote', 'tg-emoji'];

    private const ALLOWED = 'b، i، u، s، code، pre، a، blockquote، tg-spoiler و tg-emoji';
    private const STRAY = 'نماد < فقط برای شروع تگ است؛ برای نوشتن خودش &lt; بنویسید.';
    private const EMOJI_ID = 'تگ <tg-emoji> باید شناسه عددی ایموجی پرمیوم را داشته باشد؛ مثلا <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>.';
    private const EMOJI_CONTENT = 'داخل <tg-emoji> فقط یک ایموجی معمولی می‌آید — همانی که جاهایی که ایموجی پرمیوم نشان داده نمی‌شود دیده می‌شود.';

    /**
     * How long what the customer sees is: the text without its tags, entities read, counted as Telegram counts it against
     * its limits — in UTF-16 code units, an emoji beyond the basic plane two (Api\Limits::length()) —, so markup (a
     * premium emoji's tag is long) costs nothing.
     */
    public static function visibleLength(string $text): int
    {
        return Limits::length(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }

    /** The first thing Telegram would not take, in the admin's words, or null when it is fine. */
    public static function problem(string $text): ?string
    {
        /** @var list<string> $open the open tags, innermost last */
        $open = [];
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] !== '<') {
                continue;
            }

            if (($text[$i + 1] ?? '') === '/') {
                $i += 2;
                $name = strtolower(self::word($text, $i));
                self::skipSpaces($text, $i);
                if (($text[$i] ?? '') !== '>') {
                    return "تگ </{$name}> با > بسته نشده است.";
                }
                if ($open === []) {
                    return "تگ </{$name}> بسته شده ولی پیش از آن باز نشده است.";
                }
                $current = array_pop($open);
                if ($name !== '' && $name !== $current) {
                    return in_array($name, $open, true)
                        ? "پیش از </{$name}> باید </{$current}> بسته شود."
                        : "تگ </{$name}> بسته شده ولی پیش از آن باز نشده است.";
                }

                continue;
            }

            $i++;
            $name = strtolower(self::word($text, $i));
            if (preg_match('/^[a-z]/', $name) !== 1) {
                return self::STRAY;
            }
            if (!in_array($name, self::TAGS, true)) {
                return in_array($name, ['br', 'br/'], true)
                    ? 'تلگرام تگ <br> ندارد؛ برای رفتن به خط بعد فقط Enter بزنید.'
                    : "تلگرام تگ <{$name}> را نمی‌شناسد؛ تگ‌های مجاز: " . self::ALLOWED . '.';
            }

            $attributes = self::attributes($text, $i, $name);
            if (is_string($attributes)) {
                return $attributes;
            }
            if ($name === 'span' && ($attributes['class'] ?? null) !== 'tg-spoiler') {
                return 'تگ <span> فقط به صورت <span class="tg-spoiler"> پذیرفته می‌شود.';
            }
            if ($name === 'tg-emoji') {
                $problem = self::premiumEmoji($text, $i, $attributes);
                if ($problem !== null) {
                    return $problem;
                }
            }

            $problem = self::nesting($name, $open);
            if ($problem !== null) {
                return $problem;
            }
            $open[] = $name;
        }

        if ($open !== []) {
            $last = end($open);

            return "تگ <{$last}> بسته نشده است؛ آخر آن </{$last}> بگذارید.";
        }

        return null;
    }

    /**
     * The attributes up to the tag's «>» ($i ends on it), or what is wrong with them.
     *
     * @return array<string, string>|string
     */
    private static function attributes(string $text, int &$i, string $tag): array|string
    {
        $attributes = [];
        $unclosed = "تگ <{$tag}> با > بسته نشده است.";

        while (true) {
            self::skipSpaces($text, $i);
            $char = $text[$i] ?? '';
            if ($char === '') {
                return $unclosed;
            }
            if ($char === '>') {
                return $attributes;
            }

            $start = $i;
            while ($i < strlen($text) && !ctype_space($text[$i]) && $text[$i] !== '=' && $text[$i] !== '>') {
                $i++;
            }
            $name = strtolower(substr($text, $start, $i - $start));
            if ($name === '') {
                return "در تگ <{$tag}> پیش از = نام ویژگی نیامده است.";
            }
            self::skipSpaces($text, $i);
            $next = $text[$i] ?? '';
            if ($next === '') {
                return $unclosed;
            }
            if ($next !== '=') {
                if ($tag === 'blockquote' && $name === 'expandable') {
                    continue;
                }

                return "در تگ <{$tag}>، بعد از «{$name}» باید = و مقدار بیاید؛ مثلا href=\"…\".";
            }
            $i++;
            self::skipSpaces($text, $i);

            $quote = $text[$i] ?? '';
            if ($quote === '"' || $quote === "'") {
                $end = strpos($text, $quote, $i + 1);
                if ($end === false) {
                    return "مقدار {$name} در تگ <{$tag}> با {$quote} بسته نشده است.";
                }
                $attributes[$name] = html_entity_decode(substr($text, $i + 1, $end - $i - 1), ENT_QUOTES | ENT_HTML5);
                $i = $end + 1;

                continue;
            }

            $start = $i;
            while ($i < strlen($text) && (ctype_alnum($text[$i]) || $text[$i] === '.' || $text[$i] === '-')) {
                $i++;
            }
            $next = $text[$i] ?? '';
            if ($next === '') {
                return $unclosed;
            }
            if (!ctype_space($next) && $next !== '>') {
                return "مقدار {$name} در تگ <{$tag}> را داخل \"…\" بنویسید.";
            }
            $attributes[$name] = strtolower(substr($text, $start, $i - $start));
        }
    }

    /**
     * What Telegram would drop: formatting inside <code>/<pre> (a <code> right inside a <pre> is its
     * language block), a link or <code> inside a link, a quote inside a quote or a link.
     *
     * @param list<string> $open
     */
    private static function nesting(string $tag, array $open): ?string
    {
        foreach (['code', 'pre'] as $verbatim) {
            if (in_array($verbatim, $open, true) && !($tag === 'code' && end($open) === 'pre')) {
                return "داخل <{$verbatim}> تگ دیگری نمی‌شود گذاشت؛ متن آن همان‌طور که هست نشان داده می‌شود.";
            }
        }
        if (in_array('a', $open, true) && in_array($tag, ['a', 'code', 'pre', 'blockquote'], true)) {
            return "داخل لینک (<a>) تگ <{$tag}> نمی‌شود گذاشت.";
        }
        if ($tag === 'blockquote' && in_array('blockquote', $open, true)) {
            return 'نقل‌قول (<blockquote>) داخل نقل‌قول دیگر نمی‌شود.';
        }
        if ($tag === 'tg-emoji' && in_array('a', $open, true)) {
            return 'ایموجی پرمیوم (<tg-emoji>) داخل لینک نمی‌شود.';
        }

        return null;
    }

    /**
     * A premium emoji's tag, whose «>» is at `$end`: a numeric id, and one plain emoji up to its </tg-emoji> — Telegram
     * shows that emoji where the premium one cannot be (a notification), and takes nothing else there.
     *
     * @param array<string, string> $attributes
     */
    private static function premiumEmoji(string $text, int $end, array $attributes): ?string
    {
        if (preg_match('/^\d{1,20}$/', $attributes['emoji-id'] ?? '') !== 1) {
            return self::EMOJI_ID;
        }
        // No end tag: told at the end, like any tag left open.
        if (preg_match('/<\/tg-emoji\s*>/i', $text, $close, PREG_OFFSET_CAPTURE, $end + 1) !== 1) {
            return null;
        }

        $content = html_entity_decode(substr($text, $end + 1, (int) $close[0][1] - $end - 1), ENT_QUOTES | ENT_HTML5);

        return self::isEmoji($content) ? null : self::EMOJI_CONTENT;
    }

    /** One emoji — a pictograph with its modifiers and joiners, a flag, a keycap — rather than text. */
    private static function isEmoji(string $text): bool
    {
        if ($text === '' || mb_strlen($text) > 16 || preg_match('/[\s\p{L}<>&]/u', $text) === 1) {
            return false;
        }
        // A digit, a sign or a bare arrow is text; a keycap (1️⃣) and an emoji arrow (↔️) carry their marks.
        if (preg_match('/^[\p{N}\p{P}\p{Sm}]+$/u', $text) === 1) {
            return false;
        }

        return preg_match('/[\x{00A9}\x{00AE}\x{203C}-\x{3299}\x{1F000}-\x{1FAFF}\x{20E3}]/u', $text) === 1;
    }

    /** The tag name from $i: up to a space or «>» ($i ends there). */
    private static function word(string $text, int &$i): string
    {
        $start = $i;
        while ($i < strlen($text) && !ctype_space($text[$i]) && $text[$i] !== '>') {
            $i++;
        }

        return substr($text, $start, $i - $start);
    }

    private static function skipSpaces(string $text, int &$i): void
    {
        while ($i < strlen($text) && ctype_space($text[$i])) {
            $i++;
        }
    }
}
