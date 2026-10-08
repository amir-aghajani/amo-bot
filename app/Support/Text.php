<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Words cut to the room the shop gives them, by their characters — a ticket's subject, a label on a button —, one way
 * everywhere. (Words fitted to Telegram's own room — a notice, a report, a screen of the bot — are counted as Telegram
 * counts them: Telegram\Api\Limits::fit().)
 */
final class Text
{
    /**
     * `$text` as it is when it fits `$max` characters, else cut short with «…» ending it — `$max` characters at most, the
     * ellipsis among them. `$atWord` cuts at the last space when there is one in the second half of what is kept, so a
     * word is not left in pieces (a subject).
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
