<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text cut to a length in characters (a ticket's subject, a button's label) — text sized for Telegram is counted as
 * Telegram counts it instead: Telegram\Api\Limits::fit() —, and text that shows nothing told apart (blank()).
 */
final class Text
{
    /**
     * What draws nothing: a space of any kind (a no-break space among them), a control or format character (zero-width
     * ones), and the letters and marks made to look empty — the Hangul fillers, the blank Braille pattern, the combining
     * grapheme joiner, the Khmer inherent vowels — that a Telegram name made to look empty is typed with.
     */
    private const INVISIBLE = '/[\p{Z}\p{Cc}\p{Cf}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{2800}\x{3164}\x{FFA0}]+/u';

    /** Whether `$text` shows nothing at all (INVISIBLE characters alone, or none). */
    public static function blank(string $text): bool
    {
        return preg_replace(self::INVISIBLE, '', $text) === '';
    }

    /**
     * `$text` when it fits `$max` characters, else cut to `$max` with «…» included. `$atWord` cuts at the last space in
     * the second half, so no word is left in pieces.
     */
    public static function fit(string $text, int $max, bool $atWord = false): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $kept = mb_substr($text, 0, max(0, $max - 1));
        $space = $atWord ? mb_strrpos($kept, ' ') : false;
        if ($space !== false && $space >= intdiv($max, 2)) {
            $kept = mb_substr($kept, 0, $space);
        }

        return rtrim($kept) . '…';
    }
}
