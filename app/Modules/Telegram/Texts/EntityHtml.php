<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

/**
 * A Telegram message written the way the bot's texts are: its text and entities (or a caption and its entities) as
 * Telegram HTML — bold, italic, underline, strikethrough, spoilers, code, code blocks with their language, links,
 * mentions of an account, quotes, and premium emoji as `<tg-emoji emoji-id="…">😀</tg-emoji>`. What Telegram finds
 * by itself (a @handle, a link typed out, a #tag) stays plain text, and so does a `%variable%` typed in the message.
 * Entities count in UTF-16 code units, as Telegram does.
 */
final class EntityHtml
{
    /**
     * @param list<array<string, mixed>> $entities The message's `entities` (or `caption_entities`)
     */
    public static function of(string $text, array $entities): string
    {
        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $units = intdiv(strlen($utf16), 2);
        $piece = static fn(int $from, int $to): string => $to > $from
            ? htmlspecialchars((string) mb_convert_encoding(substr($utf16, $from * 2, ($to - $from) * 2), 'UTF-8', 'UTF-16LE'), ENT_NOQUOTES)
            : '';

        // Outer before inner where two start together; Telegram nests them, one inside the other.
        usort($entities, static fn(array $a, array $b): int => [(int) ($a['offset'] ?? 0), -(int) ($a['length'] ?? 0)] <=> [(int) ($b['offset'] ?? 0), -(int) ($b['length'] ?? 0)]);

        $html = '';
        $at = 0;
        /** @var list<array{int, string}> $open where each open entity ends, and its end tag — innermost last */
        $open = [];

        foreach ($entities as $entity) {
            $tags = self::tags($entity);
            $start = (int) ($entity['offset'] ?? 0);
            $end = $start + (int) ($entity['length'] ?? 0);
            if ($tags === null || $start < $at || $end <= $start || $end > $units) {
                continue;
            }

            while ($open !== [] && $open[array_key_last($open)][0] <= $start) {
                [$closeAt, $close] = array_pop($open);
                $html .= $piece($at, $closeAt) . $close;
                $at = $closeAt;
            }
            // One that would cross the entity it starts in is not Telegram's; it stays text.
            if ($open !== [] && $end > $open[array_key_last($open)][0]) {
                continue;
            }

            $html .= $piece($at, $start) . $tags[0];
            $at = $start;
            $open[] = [$end, $tags[1]];
        }

        while ($open !== []) {
            [$closeAt, $close] = array_pop($open);
            $html .= $piece($at, $closeAt) . $close;
            $at = $closeAt;
        }

        return $html . $piece($at, $units);
    }

    /**
     * The premium emoji a message carries, each once: its id and the plain emoji the message shows for it. (A list, not a
     * map by id: PHP would turn a numeric id into an integer key.)
     *
     * @param list<array<string, mixed>> $entities
     * @return list<array{id: string, emoji: string}>
     */
    public static function premiumEmoji(string $text, array $entities): array
    {
        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $found = [];

        foreach ($entities as $entity) {
            $id = (string) ($entity['custom_emoji_id'] ?? '');
            if (($entity['type'] ?? null) !== 'custom_emoji' || preg_match('/^\d{1,20}$/', $id) !== 1 || in_array($id, array_column($found, 'id'), true)) {
                continue;
            }
            $emoji = (string) mb_convert_encoding(substr($utf16, (int) ($entity['offset'] ?? 0) * 2, (int) ($entity['length'] ?? 0) * 2), 'UTF-8', 'UTF-16LE');
            $found[] = ['id' => $id, 'emoji' => $emoji];
        }

        return $found;
    }

    /**
     * An entity's tags, or null for one written as plain text.
     *
     * @param array<string, mixed> $entity
     * @return array{string, string}|null
     */
    private static function tags(array $entity): ?array
    {
        $attribute = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

        return match ((string) ($entity['type'] ?? '')) {
            'bold' => ['<b>', '</b>'],
            'italic' => ['<i>', '</i>'],
            'underline' => ['<u>', '</u>'],
            'strikethrough' => ['<s>', '</s>'],
            'spoiler' => ['<tg-spoiler>', '</tg-spoiler>'],
            'code' => ['<code>', '</code>'],
            'pre' => isset($entity['language']) && $entity['language'] !== ''
                ? ['<pre><code class="language-' . $attribute($entity['language']) . '">', '</code></pre>']
                : ['<pre>', '</pre>'],
            'text_link' => ['<a href="' . $attribute($entity['url'] ?? '') . '">', '</a>'],
            'text_mention' => isset($entity['user']['id']) ? ['<a href="tg://user?id=' . (int) $entity['user']['id'] . '">', '</a>'] : null,
            'blockquote' => ['<blockquote>', '</blockquote>'],
            'expandable_blockquote' => ['<blockquote expandable>', '</blockquote>'],
            'custom_emoji' => preg_match('/^\d{1,20}$/', (string) ($entity['custom_emoji_id'] ?? '')) === 1
                ? ['<tg-emoji emoji-id="' . $entity['custom_emoji_id'] . '">', '</tg-emoji>']
                : null,
            default => null,
        };
    }
}
