<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Modules\Telegram\Api\Limits;

/**
 * Checks a wording the way Telegram's HTML parser reads it (tdlib's: tag names in any case, attribute names exactly as
 * written), before it is stored, so a customer never gets "can't parse entities" instead of their service: only the Bot
 * API's tags, quoted attributes, tags closed in order, no stray «<». It also refuses what Telegram silently drops or
 * shows as typed (formatting inside <code>/<pre>, a link in a link, an attribute name in capitals, an entity it does not
 * read). A premium emoji is `<tg-emoji emoji-id="…">😀</tg-emoji>`: a numeric id and the plain emoji it stands for. A
 * date and time is `<tg-time unix="…" format="…">…</tg-time>`: a Unix time, how the customer's own app writes it
 * (TIME_FORMAT, none for the words as they are) and the words — what is shown where it cannot be.
 */
final class TelegramHtml
{
    /** The tags Telegram reads; `span` only as a spoiler. */
    private const TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'tg-spoiler', 'span', 'a', 'code', 'pre', 'blockquote', 'tg-emoji', 'tg-time'];

    /** The attributes Telegram reads of a tag, by these names alone: written in capitals, it passes them by. */
    private const ATTRIBUTES = ['a' => ['href'], 'span' => ['class'], 'code' => ['class'], 'tg-emoji' => ['emoji-id'], 'blockquote' => ['expandable'], 'tg-time' => ['unix', 'format']];

    /**
     * The tags of what holds no other entity but formatting (Telegram: "all other entities can't contain each other"), and
     * what may not go inside one.
     */
    private const WHOLE = ['a' => 'لینک (<a>)', 'tg-time' => 'زمان (<tg-time>)'];
    private const NOT_IN_WHOLE = ['a', 'code', 'pre', 'blockquote', 'tg-emoji', 'tg-time'];

    /**
     * A date and time's `format` (the Bot API's date-time entity formatting): «r» (relative to now) alone, or the weekday
     * «w», a short or long date «d»/«D» and a short or long time «t»/«T», each at most once in that order — or nothing: the
     * words as written.
     */
    private const TIME_FORMAT = '/^(?:r|w?[dD]?[tT]?)$/D';

    /** The latest Unix time Telegram's dates hold (a signed 32-bit number). */
    private const TIME_MOST = 2_147_483_647;

    /** The named entities Telegram reads, by the character each stands for; any other it shows as typed. */
    private const NAMED = ['lt' => 0x3C, 'gt' => 0x3E, 'amp' => 0x26, 'quot' => 0x22];

    /** An entity as Telegram reads one from its «&»: a number (a lowercase x before hex digits) or letters, «;» optional. */
    private const ENTITY = '&(?:#x[0-9a-fA-F]*|#[0-9]*|[A-Za-z]*);?';

    /** What an admin means as an entity: a number, or a name closed by «;» — unlike the «&» of «R&D». */
    private const LOOKS_LIKE_ENTITY = '/\G&(?:#[xX]?[0-9a-fA-F]+;?|[A-Za-z][A-Za-z0-9]*;)/';

    private const ALLOWED = 'b، i، u، s، code، pre، a، blockquote، tg-spoiler، tg-emoji و tg-time';
    private const STRAY = 'نماد < فقط برای شروع تگ است؛ برای نوشتن خودش &lt; بنویسید.';
    private const EMOJI_ID = 'تگ <tg-emoji> باید شناسه عددی ایموجی پرمیوم را داشته باشد؛ مثلا <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>.';
    private const EMOJI_CONTENT = 'داخل <tg-emoji> فقط یک ایموجی معمولی می‌آید — همانی که جاهایی که ایموجی پرمیوم نشان داده نمی‌شود دیده می‌شود.';
    private const TIME_UNIX = 'تگ <tg-time> باید زمان را به ثانیه‌های یونیکس در unix داشته باشد؛ مثلا <tg-time unix="1767225600" format="D">۱۱ دی ۱۴۰۴</tg-time>.';
    private const TIME_FORMAT_REFUSED = 'مقدار format در تگ <tg-time> یا r است، یا از w، سپس d یا D، سپس t یا T ساخته می‌شود (مثلا wDT)؛ یا format را ننویسید تا متن همان‌طور که هست نشان داده شود.';
    private const TIME_FORMAT_BARE = 'مقدار format در تگ <tg-time> را داخل "…" بنویسید؛ بدون آن تلگرام حروف بزرگ آن را کوچک می‌خواند.';
    private const TIME_CONTENT = 'داخل <tg-time> متنی بنویسید؛ همان است که جاهایی که زمان نشان داده نمی‌شود دیده می‌شود.';

    /** What the customer sees of it: tags dropped, entities read as Telegram reads them. */
    public static function visible(string $text): string
    {
        return self::decode(strip_tags($text));
    }

    /** The length of what the customer sees (visible()) as Telegram counts it (Limits::length()). */
    public static function visibleLength(string $text): int
    {
        return Limits::length(self::visible($text));
    }

    /** The text with the entities Telegram reads decoded (codePoint()); any other «&» stays as typed, as Telegram shows it. */
    public static function decode(string $text): string
    {
        return (string) preg_replace_callback('/' . self::ENTITY . '/', static function (array $entity): string {
            $code = self::codePoint($entity[0]);
            $char = $code === null ? false : mb_chr($code, 'UTF-8');

            return $char === false ? $entity[0] : $char;
        }, $text);
    }

    /** The first thing Telegram would not take, worded for the admin, or null when it is fine. */
    public static function problem(string $text): ?string
    {
        /** @var list<string> $open the open tags, innermost last */
        $open = [];
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '&') {
                $problem = self::entity($text, $i);
                if ($problem !== null) {
                    return $problem;
                }

                continue;
            }
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
            $problem = match ($name) {
                'tg-emoji' => self::premiumEmoji($text, $i, $attributes),
                'tg-time' => self::time($text, $i, $attributes),
                default => null,
            };
            if ($problem !== null) {
                return $problem;
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
            $name = substr($text, $start, $i - $start);
            if ($name === '') {
                return "در تگ <{$tag}> پیش از = نام ویژگی نیامده است.";
            }
            $read = strtolower($name);
            if ($name !== $read && in_array($read, self::ATTRIBUTES[$tag] ?? [], true)) {
                return "در تگ <{$tag}> نام «{$name}» را با حروف کوچک بنویسید: {$read}؛ تلگرام آن را با حروف بزرگ نمی‌خواند.";
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
                $attributes[$name] = self::decode(substr($text, $i + 1, $end - $i - 1));
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
            // A bare value is read in lower case: a date's format would mean another (D, a long date, read as d).
            $value = substr($text, $start, $i - $start);
            if ($tag === 'tg-time' && $name === 'format' && $value !== strtolower($value)) {
                return self::TIME_FORMAT_BARE;
            }
            $attributes[$name] = strtolower($value);
        }
    }

    /**
     * What Telegram would drop: formatting inside <code>/<pre> (a <code> right inside a <pre> is its language block); a
     * link, code, a quote, a premium emoji or a date inside a link or a date (WHOLE); a quote inside a quote.
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
        foreach (self::WHOLE as $whole => $words) {
            if (in_array($whole, $open, true) && in_array($tag, self::NOT_IN_WHOLE, true)) {
                return "داخل {$words} تگ <{$tag}> نمی‌شود گذاشت.";
            }
        }
        if ($tag === 'blockquote' && in_array('blockquote', $open, true)) {
            return 'نقل‌قول (<blockquote>) داخل نقل‌قول دیگر نمی‌شود.';
        }

        return null;
    }

    /**
     * A date and time's tag, whose «>» is at `$end`: a Unix time in `unix`, a `format` Telegram reads (TIME_FORMAT), and
     * words up to its </tg-time> — what the customer sees where the time cannot be shown, and as they are without a format.
     *
     * @param array<string, string> $attributes
     */
    private static function time(string $text, int $end, array $attributes): ?string
    {
        $unix = $attributes['unix'] ?? '';
        if (preg_match('/^[0-9]{1,10}$/D', $unix) !== 1 || (int) $unix < 1 || (int) $unix > self::TIME_MOST) {
            return self::TIME_UNIX;
        }
        if (preg_match(self::TIME_FORMAT, $attributes['format'] ?? '') !== 1) {
            return self::TIME_FORMAT_REFUSED;
        }
        // No end tag: reported at the end, like any tag left open.
        if (preg_match('/<\/tg-time\s*>/i', $text, $close, PREG_OFFSET_CAPTURE, $end + 1) !== 1) {
            return null;
        }

        return trim(self::visible(substr($text, $end + 1, (int) $close[0][1] - $end - 1))) === '' ? self::TIME_CONTENT : null;
    }

    /**
     * A premium emoji's tag, whose «>» is at `$end`: a numeric id, and one plain emoji up to its </tg-emoji> — Telegram
     * shows that emoji where the premium one cannot be (a notification), and takes nothing else there.
     *
     * @param array<string, string> $attributes
     */
    private static function premiumEmoji(string $text, int $end, array $attributes): ?string
    {
        if (preg_match('/^\d{1,20}$/D', $attributes['emoji-id'] ?? '') !== 1) {
            return self::EMOJI_ID;
        }
        // No end tag: reported at the end, like any tag left open.
        if (preg_match('/<\/tg-emoji\s*>/i', $text, $close, PREG_OFFSET_CAPTURE, $end + 1) !== 1) {
            return null;
        }

        $content = self::decode(substr($text, $end + 1, (int) $close[0][1] - $end - 1));

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

    /**
     * The «&» at `$i` as Telegram reads it: an entity it reads ($i ends on its last byte), a «&» of the words — or what is
     * wrong: a number that is half a character (Telegram refuses the whole message), or what an admin meant as an entity
     * and Telegram shows as typed (`&nbsp;`, `&#X41;`).
     */
    private static function entity(string $text, int &$i): ?string
    {
        $entity = preg_match('/\G' . self::ENTITY . '/', $text, $read, 0, $i) === 1 ? $read[0] : '&';
        $code = self::codePoint($entity);
        if ($code !== null && ($code < 0xD800 || $code > 0xDFFF)) {
            $i += strlen($entity) - 1;

            return null;
        }
        if ($code !== null) {
            return "«{$entity}» نیمی از یک نویسه است و تلگرام پیامی با آن را نمی‌پذیرد؛ خود نویسه را بنویسید، یا عدد کامل آن را مثل &#128512;.";
        }
        if (preg_match(self::LOOKS_LIKE_ENTITY, $text, $meant, 0, $i) === 1) {
            return "تلگرام «{$meant[0]}» را نمی‌شناسد و همان‌طور که نوشته شده نشان می‌دهد؛ فقط &lt; و &gt; و &amp; و &quot; و نمادهای عددی مثل &#128512; یا &#x1F600; خوانده می‌شوند. برای خود نماد & بنویسید &amp;.";
        }

        return null;
    }

    /**
     * The character an entity (ENTITY) stands for as Telegram reads it — one of NAMED, or a number: decimal, or hex after
     * a lowercase x —; null where it reads none and shows it as typed: an unknown name, no digits, zero, past the last
     * character, or too long to be one.
     */
    private static function codePoint(string $entity): ?int
    {
        if (!str_starts_with($entity, '&#')) {
            return self::NAMED[trim($entity, '&;')] ?? null;
        }
        $number = rtrim($entity, ';');
        $hex = ($number[2] ?? '') === 'x';
        $digits = substr($number, $hex ? 3 : 2);
        if ($digits === '' || strlen($number) >= 10) {
            return null;
        }
        $code = $hex ? (int) hexdec($digits) : (int) $digits;

        return $code === 0 || $code >= 0x10FFFF ? null : $code;
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
