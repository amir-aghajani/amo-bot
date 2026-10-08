<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

/**
 * What Telegram takes, in one place: past these it refuses the call (or cuts what it shows). Lengths are characters as
 * Telegram counts them — UTF-16 code units (length()): a Persian or Latin letter is one, an emoji beyond the basic plane
 * (😀, 👍) two — of what it shows: tags in an HTML text cost nothing (Texts\TelegramHtml::visibleLength()).
 */
final class Limits
{
    /** A message's text. */
    public const MESSAGE = 4096;

    /** A picture's caption. */
    public const CAPTION = 1024;

    /** The toast or popup answering a press. */
    public const POPUP = 200;

    /** A group's title, a forum topic's name. */
    public const TITLE = 128;

    /** Premium emoji asked for in one getCustomEmojiStickers. */
    public const CUSTOM_EMOJI_IDS = 200;

    /** Messages a minute to one group. */
    public const GROUP_PER_MINUTE = 20;

    /**
     * How long a plain text is as Telegram counts it against these limits: in UTF-16 code units — what mb_strlen() counts
     * as one character, an emoji beyond the basic plane, is two.
     */
    public static function length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * The longest start of a plain text Telegram counts as `$units` at most (length()) — a character never cut in two:
     * an emoji whose second half would be past the room is left out whole.
     */
    public static function cut(string $text, int $units): string
    {
        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        if (strlen($utf16) <= max(0, $units) * 2) {
            return $text;
        }

        $kept = substr($utf16, 0, max(0, $units) * 2);
        // A unit kept last that opens a pair (a high surrogate, 0xD800–0xDBFF; its high byte last, little-endian) is half
        // a character.
        if ($kept !== '' && (ord($kept[strlen($kept) - 1]) & 0xFC) === 0xD8) {
            $kept = substr($kept, 0, -2);
        }

        return (string) mb_convert_encoding($kept, 'UTF-8', 'UTF-16LE');
    }

    /**
     * A plain text as it is when Telegram counts it `$units` at most (length()), else cut short with «…» ending it —
     * `$units` at most, the ellipsis among them, no character cut in two (cut()): the words of a notice, a report or a
     * screen of the bot fitted to Telegram's room. (A text cut by its characters — a ticket's subject — is App\Support\Text's.)
     */
    public static function fit(string $text, int $units): string
    {
        return self::length($text) <= $units ? $text : rtrim(self::cut($text, $units - 1)) . '…';
    }
}
