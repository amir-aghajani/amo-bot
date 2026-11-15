<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Texts\EntityHtml;

/**
 * A bot text's wording from a message an admin sent the bot, kept the way the panel keeps it: a message, a caption or a
 * part as Telegram HTML — its formatting and premium emoji (EntityHtml) —; a popup or a button as typed, its premium
 * emoji written as the panel's picker writes them (`<tg-emoji emoji-id="…">😀</tg-emoji>`, a button's first one its
 * icon) and nothing else read. Offsets are UTF-16 code units, as Telegram counts them.
 */
final class Wording
{
    /**
     * The wording of `$message` (its text or caption, with their entities); `$html` for a kind Telegram reads as HTML.
     *
     * @param array<string, mixed> $message
     */
    public static function of(array $message, bool $html): string
    {
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $entities = array_values(array_filter((array) ($message['entities'] ?? $message['caption_entities'] ?? []), is_array(...)));

        return trim($html ? EntityHtml::of($text, $entities) : self::plain($text, $entities));
    }

    /**
     * The words as typed, each premium emoji in its tag.
     *
     * @param list<array<string, mixed>> $entities
     */
    private static function plain(string $text, array $entities): string
    {
        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = intdiv(strlen($utf16), 2);
        $piece = static fn(int $from, int $to): string => $to > $from ? (string) mb_convert_encoding(substr($utf16, $from * 2, ($to - $from) * 2), 'UTF-8', 'UTF-16LE') : '';
        $emoji = array_values(array_filter($entities, static fn(array $entity): bool => ($entity['type'] ?? null) === 'custom_emoji' && preg_match('/^\d{1,20}$/', (string) ($entity['custom_emoji_id'] ?? '')) === 1));
        usort($emoji, static fn(array $a, array $b): int => (int) ($a['offset'] ?? 0) <=> (int) ($b['offset'] ?? 0));

        $plain = '';
        $at = 0;
        foreach ($emoji as $entity) {
            $start = (int) ($entity['offset'] ?? 0);
            $end = $start + (int) ($entity['length'] ?? 0);
            if ($start < $at || $end <= $start || $end > $units) {
                continue;
            }
            $plain .= $piece($at, $start) . '<tg-emoji emoji-id="' . (string) $entity['custom_emoji_id'] . '">' . $piece($start, $end) . '</tg-emoji>';
            $at = $end;
        }

        return $plain . $piece($at, $units);
    }
}
