<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * The bot's words — Telegram HTML, as BotTexts renders a text — in the two forms a website (and an email) shows them in:
 * plain() — the tags dropped, the entities read, a premium emoji its plain emoji — and html(), safe HTML: Telegram's
 * formatting as the web has it and nothing else — b/strong, i/em, u/ins, s/strike/del, code, pre and blockquote as they
 * are, a spoiler as `<span class="tg-spoiler">`, a link only to an http(s) address (a bare t.me one made https), a
 * premium emoji its plain emoji, a line break `<br>` (but inside a <pre>, which keeps its lines). Every other tag gives
 * way to its text, every attribute but a link's href goes, and every piece of text is escaped: what comes out is written
 * here, never the source passed through — so a text that is no Telegram HTML (a tag left open or closed out of turn, a
 * script, a handler in an attribute) still comes out as harmless markup, every element it opens closed in order.
 */
final class WebText
{
    /** Telegram's formatting the web has as it is. */
    private const KEPT = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'code', 'pre', 'blockquote'];

    /** A spoiler on the web: a span the site hides until it is pressed. */
    private const SPOILER = ['<span class="tg-spoiler">', '</span>'];

    /** Elements that never hold anything — no end tag closes them. */
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /** The text alone: every tag dropped, the entities read — a premium emoji is the plain emoji it carries. */
    public static function plain(string $telegramHtml): string
    {
        $plain = '';
        foreach (self::tokens($telegramHtml) as [$kind, $value]) {
            if ($kind === 'text') {
                $plain .= $value;
            }
        }

        return trim($plain);
    }

    /** The text as safe HTML (see the class): only the formatting kept, every element closed, every text escaped. */
    public static function html(string $telegramHtml): string
    {
        $html = '';
        /** @var list<array{string, string}> $open the elements open, innermost last: the source's name, and the end tag written for it ('' for none) */
        $open = [];

        foreach (self::tokens($telegramHtml) as [$kind, $value, $attributes]) {
            if ($kind === 'text') {
                $escaped = self::escape($value);
                $html .= in_array(['pre', '</pre>'], $open, true) ? $escaped : (string) preg_replace('/\r\n?|\n/', '<br>', $escaped);

                continue;
            }

            if ($kind === 'close') {
                // The innermost element of that name closes, every one opened inside it with it; one open nowhere is dropped.
                $at = count($open) - 1;
                while ($at >= 0 && $open[$at][0] !== $value) {
                    $at--;
                }
                while ($at >= 0 && count($open) > $at) {
                    $html .= array_pop($open)[1];
                }

                continue;
            }

            // A void or self-closed element holds nothing: nothing to write.
            if ($kind === 'open' && !in_array($value, self::VOID, true)) {
                [$start, $end] = self::element($value, $attributes, $open);
                $html .= $start;
                $open[] = [$value, $end];
            }
        }

        while ($open !== []) {
            $html .= array_pop($open)[1];
        }

        return $html;
    }

    /**
     * What an element of the source is written as: its start and end tags — Telegram's formatting the web keeps, a
     * spoiler as a span, a link to an http(s) address that is not inside another link — or none at all, its text left.
     *
     * @param array<string, string> $attributes
     * @param list<array{string, string}> $open
     * @return array{string, string}
     */
    private static function element(string $name, array $attributes, array $open): array
    {
        if (in_array($name, self::KEPT, true)) {
            return ["<{$name}>", "</{$name}>"];
        }
        if ($name === 'tg-spoiler' || ($name === 'span' && strtolower(trim($attributes['class'] ?? '')) === 'tg-spoiler')) {
            return self::SPOILER;
        }
        $href = $name === 'a' ? self::href($attributes['href'] ?? '') : null;
        if ($href !== null && !in_array(['a', '</a>'], $open, true)) {
            return ['<a href="' . self::escape($href) . '">', '</a>'];
        }

        return ['', ''];
    }

    /** A link's address the web may follow: http(s) with a host — a bare t.me one made https —, else null. */
    private static function href(string $href): ?string
    {
        $href = trim($href);
        if (preg_match('/[\x00-\x20\x7F]/', $href) === 1) {
            return null;
        }
        if (preg_match('~^(?:t|telegram)\.me/~i', $href) === 1) {
            $href = 'https://' . $href;
        }

        return preg_match('~^https?://[^/?#\\\\]~i', $href) === 1 ? $href : null;
    }

    /**
     * The source as pieces: text (its entities read), a start tag (its name in lower case and its attributes; a void
     * or self-closed one as `void`), an end tag. A «<» that opens no tag — no letter after it, or never closed — is text.
     *
     * @return \Generator<int, array{string, string, array<string, string>}>
     */
    private static function tokens(string $source): \Generator
    {
        $source = trim($source);
        $length = strlen($source);
        $text = '';
        $at = 0;

        while ($at < $length) {
            $lt = strpos($source, '<', $at);
            if ($lt === false) {
                $text .= substr($source, $at);

                break;
            }
            $text .= substr($source, $at, $lt - $at);
            $tag = self::tag($source, $lt);
            if ($tag === null) {
                $text .= '<';
                $at = $lt + 1;

                continue;
            }
            if ($text !== '') {
                yield ['text', self::decode($text), []];
                $text = '';
            }
            yield [$tag[0], $tag[1], $tag[2]];
            $at = $tag[3];
        }

        if ($text !== '') {
            yield ['text', self::decode($text), []];
        }
    }

    /**
     * The tag at `$at` (a «<»): its kind (open, void, close), its name, its attributes and where the source goes on after
     * it — or null when it is none.
     *
     * @return array{string, string, array<string, string>, int}|null
     */
    private static function tag(string $source, int $at): ?array
    {
        $closing = ($source[$at + 1] ?? '') === '/';
        if (preg_match('/\G[A-Za-z][A-Za-z0-9-]*/', $source, $name, 0, $at + ($closing ? 2 : 1)) !== 1) {
            return null;
        }
        $name = strtolower($name[0]);
        $i = $at + ($closing ? 2 : 1) + strlen($name);
        $length = strlen($source);

        if ($closing) {
            $end = strpos($source, '>', $i);

            return $end === false ? null : ['close', $name, [], $end + 1];
        }

        $attributes = [];
        $selfClosed = false;
        while (true) {
            while ($i < $length && (ctype_space($source[$i]) || $source[$i] === '/')) {
                $selfClosed = $source[$i] === '/';
                $i++;
            }
            if ($i >= $length) {
                return null;
            }
            if ($source[$i] === '>') {
                return [$selfClosed ? 'void' : 'open', $name, $attributes, $i + 1];
            }

            $selfClosed = false;
            $start = $i;
            do {
                $i++;
            } while ($i < $length && !ctype_space($source[$i]) && !in_array($source[$i], ['=', '>', '/'], true));
            $attribute = strtolower(substr($source, $start, $i - $start));
            while ($i < $length && ctype_space($source[$i])) {
                $i++;
            }

            $value = '';
            if (($source[$i] ?? '') === '=') {
                $i++;
                while ($i < $length && ctype_space($source[$i])) {
                    $i++;
                }
                $quote = $source[$i] ?? '';
                if ($quote === '"' || $quote === "'") {
                    $end = strpos($source, $quote, $i + 1);
                    if ($end === false) {
                        return null;
                    }
                    $value = substr($source, $i + 1, $end - $i - 1);
                    $i = $end + 1;
                } else {
                    $start = $i;
                    while ($i < $length && !ctype_space($source[$i]) && $source[$i] !== '>') {
                        $i++;
                    }
                    $value = substr($source, $start, $i - $start);
                }
            }
            // The first of two of a name counts, as a browser reads it.
            $attributes[$attribute] ??= self::decode($value);
        }
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
