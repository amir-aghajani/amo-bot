<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Texts;

use App\Support\Text;

/**
 * A wording Telegram shows as typed — a popup's (a tapped button's notice) or a button's label (TextKind::html() false) —,
 * which may still carry premium emoji, written as the panel's picker and `/emoji` write them:
 * `<tg-emoji emoji-id="…">😀</tg-emoji>`. Telegram reads no tag there, so the bot turns each into what it can show: a
 * button's one premium emoji, at the start of its label, becomes the button's icon (Telegram draws it before the label;
 * the bot's API client does this as it sends — icons()) — also one right after the label's own leading emoji, as a
 * name's comes after a wording's symbol («🌍 %server%»), which it then takes the place of —; a notice shows each one's
 * plain emoji; and the wording sent as a message keeps them (html()).
 */
final class PlainWording
{
    /** A premium emoji as the wording carries it: its id, and the plain emoji Telegram shows where the premium one cannot be. */
    private const TAG = '~<tg-emoji emoji-id="(\d{1,20})">([^<]*)</tg-emoji>~u';

    /** What Telegram would show as typed: any tag at all, a premium emoji written another way among them. */
    private const ANY_TAG = '/<\/?[a-z][a-z-]*[^>]*>/i';

    public const NO_TAGS = 'این متن قالب‌بندی ندارد و تگ‌ها همان‌طور که نوشته شده‌اند نشان داده می‌شوند؛ آن‌ها را بردارید. تنها تگی که پذیرفته می‌شود ایموجی پرمیوم (<tg-emoji>) است.';
    public const FORM = 'ایموجی پرمیوم را همان‌طور بنویسید که «ایموجی پرمیوم» می‌گذارد؛ مثلا <tg-emoji emoji-id="5368324170671202286">👍</tg-emoji>.';
    public const BUTTON_PLACE = 'دکمه فقط یک ایموجی پرمیوم می‌گیرد، آن هم اول متن: تلگرام آن را آیکون دکمه، پیش از متن آن، نشان می‌دهد.';
    public const BUTTON_WORDS = 'کنار ایموجی پرمیوم، متنی هم برای دکمه بنویسید.';

    /** What a notice shows of the wording: each premium emoji's plain one in its place. */
    public static function shown(string $text): string
    {
        return (string) preg_replace(self::TAG, '$2', $text);
    }

    /** The wording for a message (Telegram's HTML): escaped, its premium emoji kept as premium emoji. */
    public static function html(string $text): string
    {
        $html = '';
        $at = 0;
        preg_match_all(self::TAG, $text, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            [$whole, $start] = $tag[0];
            $html .= htmlspecialchars(substr($text, $at, $start - $at))
                . '<tg-emoji emoji-id="' . $tag[1][0] . '">' . htmlspecialchars($tag[2][0]) . '</tg-emoji>';
            $at = $start + strlen($whole);
        }

        return $html . htmlspecialchars(substr($text, $at));
    }

    /**
     * A button's label as Telegram takes it: its leading premium emoji's id — its icon, null without one — and the label
     * without it; any other premium emoji in it shows its plain one. A premium emoji right after the label's own leading
     * emoji (a plain one and spaces: a name's after a wording's symbol, «🌍 %server%») is its icon too, in that emoji's
     * place.
     *
     * @return array{?string, string}
     */
    public static function icon(string $label): array
    {
        $own = PremiumText::leadingEmoji($label);
        $lead = $own === null ? 0 : strlen($own) + strspn($label, ' ', strlen($own));
        if (preg_match(self::TAG, $label, $tag, PREG_OFFSET_CAPTURE) === 1 && ($tag[0][1] === 0 || ($lead > strlen((string) $own) && $tag[0][1] === $lead))) {
            $rest = ltrim(self::shown(substr($label, $tag[0][1] + strlen($tag[0][0]))));
            // A label of its premium emoji alone keeps it as words: Telegram takes no button without them.
            if ($rest !== '') {
                return [$tag[1][0], $rest];
            }
        }

        return [null, self::shown($label)];
    }

    /**
     * The reply markup with each button's leading premium emoji as its icon (icon()) — a button with an icon already keeps
     * it, its label's premium emoji then plain —, or null when no label carries one.
     *
     * @param array<string, mixed> $markup
     * @return array<string, mixed>|null
     */
    public static function icons(array $markup): ?array
    {
        return self::eachLabel($markup, static function (array $button, string $label): array {
            [$icon, $text] = self::icon($label);
            if ($icon !== null && !array_key_exists('icon_custom_emoji_id', $button)) {
                $button['icon_custom_emoji_id'] = $icon;
            } else {
                $text = self::shown($label);
            }

            return ['text' => $text] + $button;
        });
    }

    /**
     * The reply markup with each label's premium emoji plain (shown()), or null when no label carries one.
     *
     * @param array<string, mixed> $markup
     * @return array<string, mixed>|null
     */
    public static function plainLabels(array $markup): ?array
    {
        return self::eachLabel($markup, static fn(array $button, string $label): array => ['text' => self::shown($label)] + $button);
    }

    /**
     * What is wrong with the premium emoji of a popup's or a button's wording, worded for the admin, or null: a tag other
     * than a premium emoji, one written otherwise than the picker writes it, one that holds no single plain emoji — and a
     * button's: more than one, one anywhere but at the start of the label, or one with no words beside it.
     */
    public static function problem(TextKind $kind, string $value): ?string
    {
        $count = 0;
        $first = null;
        $content = null;
        $rest = (string) preg_replace_callback(self::TAG, static function (array $tag) use (&$count, &$first, &$content): string {
            $count++;
            $first ??= $tag[0];
            if (!TelegramHtml::isEmoji($tag[2])) {
                $content ??= TelegramHtml::EMOJI_CONTENT;
            }

            return '';
        }, $value);

        if ($content !== null) {
            return $content;
        }
        if (preg_match('/<\/?tg-emoji\b/i', $rest) === 1) {
            return self::FORM;
        }
        if (preg_match(self::ANY_TAG, $rest) === 1) {
            return self::NO_TAGS;
        }
        if ($kind !== TextKind::Button || $count === 0) {
            return null;
        }
        if ($count > 1 || !str_starts_with($value, (string) $first)) {
            return self::BUTTON_PLACE;
        }

        return Text::blank(substr($value, strlen((string) $first))) ? self::BUTTON_WORDS : null;
    }

    /**
     * The reply markup with `$change` applied to every button whose label carries a premium emoji, or null when none does.
     *
     * @param array<string, mixed> $markup
     * @param \Closure(array<string, mixed>, string): array<string, mixed> $change The button and its label, to the button as sent
     * @return array<string, mixed>|null
     */
    private static function eachLabel(array $markup, \Closure $change): ?array
    {
        $changed = false;
        foreach (['inline_keyboard', 'keyboard'] as $kind) {
            foreach (is_array($markup[$kind] ?? null) ? $markup[$kind] : [] as $r => $row) {
                foreach (is_array($row) ? $row : [] as $b => $button) {
                    if (is_array($button) && is_string($button['text'] ?? null) && preg_match(self::TAG, $button['text']) === 1) {
                        $markup[$kind][$r][$b] = $change($button, $button['text']);
                        $changed = true;
                    }
                }
            }
        }

        return $changed ? $markup : null;
    }
}
