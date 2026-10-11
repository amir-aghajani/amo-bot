<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Api;

/**
 * Telegram's limits in one place. Lengths are counted as Telegram counts them: UTF-16 code units of what is shown (an
 * emoji beyond the basic plane is two; tags cost nothing — Texts\TelegramHtml::visibleLength()).
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

    /** A plain text's length as Telegram counts it: UTF-16 code units. */
    public static function length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** The longest start of a text within `$units` UTF-16 units, never cutting a character (an emoji) in two. */
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

    /** A text as it is when it fits `$units`, else cut with «…» to `$units` at most (the ellipsis included). */
    public static function fit(string $text, int $units): string
    {
        return self::length($text) <= $units ? $text : rtrim(self::cut($text, $units - 1)) . '…';
    }
}
