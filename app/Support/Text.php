<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Text cut to a length in characters (a ticket's subject, a button's label). Text sized for Telegram is counted as
 * Telegram counts it instead: Telegram\Api\Limits::fit().
 */
final class Text
{
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
