<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Emoji;

use App\Core\Exceptions\ValidationException;
use App\Modules\Settings\Services\Settings;
use App\Modules\Telegram\Models\CustomEmoji;
use App\Modules\Telegram\Texts\PremiumText;
use App\Modules\Telegram\Texts\TelegramHtml;
use App\Modules\Telegram\Texts\TextKind;

/**
 * «ایموجی پرمیوم متن‌ها»: plain emoji the shop gave a premium emoji each (❤️ → its own premium heart), drawn premium in
 * every text of the bot's — the default wordings and the admin's alike —, in the shop's own words only: a filled-in
 * value (a name, a link, what a customer typed) stays as it is. A premium emoji a wording carries itself (`<tg-emoji>`)
 * is its own and wins; so does a menu button's own icon, and a name's own premium emoji in a button's label.
 *
 * Kept per shop in the settings (`bot.premium_texts`). Turned on only once the bot was found able to send premium emoji
 * (PremiumEmojiStatus: its maker has Telegram Premium); the map itself may be laid out before.
 */
final class EmojiMap
{
    /** The most plain emoji a shop maps. */
    public const MAX = 100;

    /** Where premium emoji never go: inside one already, code, a link's or a date's words. */
    private const SKIP = ['tg-emoji', 'code', 'pre', 'a', 'tg-time'];

    private const KEY = 'bot.premium_texts';

    /**
     * What makes one of TelegramHtml::isEmoji()'s an emoji (drawnAsEmoji()): its variation selector or keycap mark, a
     * pictograph past U+1F000, or one of the few below U+1F000 Unicode counts as emoji (its Emoji property: ❤ ☎ ⚠ ✅ ✔ ⭐ ©
     * …) — not the other signs of those blocks (━ ★ ✓ ● ■ №).
     */
    private const DRAWN = '/[\x{FE0F}\x{20E3}\x{1F000}-\x{1FAFF}\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2122}\x{2139}\x{2194}-\x{2199}'
        . '\x{21A9}\x{21AA}\x{231A}\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{24C2}\x{25AA}\x{25AB}\x{25B6}'
        . '\x{25C0}\x{25FB}-\x{25FE}\x{2600}-\x{2604}\x{260E}\x{2611}\x{2614}\x{2615}\x{2618}\x{261D}\x{2620}\x{2622}\x{2623}'
        . '\x{2626}\x{262A}\x{262E}\x{262F}\x{2638}-\x{263A}\x{2640}\x{2642}\x{2648}-\x{2653}\x{265F}\x{2660}\x{2663}\x{2665}'
        . '\x{2666}\x{2668}\x{267B}\x{267E}\x{267F}\x{2692}-\x{2697}\x{2699}\x{269B}\x{269C}\x{26A0}\x{26A1}\x{26A7}\x{26AA}'
        . '\x{26AB}\x{26B0}\x{26B1}\x{26BD}\x{26BE}\x{26C4}\x{26C5}\x{26C8}\x{26CE}\x{26CF}\x{26D1}\x{26D3}\x{26D4}\x{26E9}'
        . '\x{26EA}\x{26F0}-\x{26F5}\x{26F7}-\x{26FA}\x{26FD}\x{2702}\x{2705}\x{2708}-\x{270D}\x{270F}\x{2712}\x{2714}\x{2716}'
        . '\x{271D}\x{2721}\x{2728}\x{2733}\x{2734}\x{2744}\x{2747}\x{274C}\x{274E}\x{2753}-\x{2755}\x{2757}\x{2763}\x{2764}'
        . '\x{2795}-\x{2797}\x{27A1}\x{27B0}\x{27BF}\x{2934}\x{2935}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B50}\x{2B55}\x{3030}'
        . '\x{303D}\x{3297}\x{3299}]/u';

    public const NOT_ABLE = 'ربات هنوز نمی‌تواند ایموجی پرمیوم بفرستد، یا بررسی نشده است: اول «بررسی دوباره» را بزنید. ربات فقط وقتی ایموجی پرمیوم می‌فرستد که حسابی که آن را در @BotFather ساخته تلگرام پرمیوم داشته باشد.';
    public const NOT_EMOJI = 'یک ایموجی معمولی بنویسید؛ همان که ایموجی پرمیوم در متن‌ها جای آن می‌نشیند.';
    public const ID_REFUSED = 'ایموجی پرمیوم را از فهرست ایموجی‌های پرمیوم ربات انتخاب کنید.';
    public const NOT_KNOWN = 'این ایموجی پرمیوم در فهرست ربات نیست؛ اول آن را با /emoji به ربات نشان دهید.';
    public const TWICE_EMOJI = 'برای هر ایموجی معمولی یک ایموجی پرمیوم کافی است؛ یکی از این دو را بردارید.';
    public const TWICE_ID = 'این ایموجی پرمیوم جای ایموجی دیگری در فهرست نشسته است.';

    public function __construct(
        private readonly Settings $settings,
        private readonly PremiumEmojiStatus $status,
    ) {}

    /**
     * What is kept: the switch and the map, in the admin's order.
     *
     * @return array{enabled: bool, items: list<array{emoji: string, emoji_id: string}>}
     */
    public function stored(): array
    {
        $kept = $this->settings->get(self::KEY);
        $items = [];
        foreach (is_array($kept) && is_array($kept['items'] ?? null) ? $kept['items'] : [] as $item) {
            if (is_array($item) && is_string($item['emoji'] ?? null) && is_string($item['emoji_id'] ?? null)) {
                $items[] = ['emoji' => $item['emoji'], 'emoji_id' => $item['emoji_id']];
            }
        }

        return ['enabled' => is_array($kept) && ($kept['enabled'] ?? false) === true, 'items' => $items];
    }

    /** Whether the bot may be told to draw them now: found able at the last look (PremiumEmojiStatus). */
    public function canEnable(): bool
    {
        return ($this->status->current()['ok'] ?? false) === true;
    }

    /**
     * Keep the switch and the map the admin sent (`enabled`, `items`: [{emoji, emoji_id}]). Turned on only while the bot
     * was found able (canEnable()); left on, the map may change whatever was found since. A premium emoji new to the map
     * must be one the bot knows (Emoji\CustomEmojis: it came in a real message, so Telegram has it).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function save(array $input): void
    {
        $had = $this->stored();
        $errors = [];

        $enabled = $input['enabled'] ?? $had['enabled'];
        if (!is_bool($enabled)) {
            $errors['enabled'][] = 'روشن یا خاموش بودن را مشخص کنید.';
        } elseif ($enabled && !$had['enabled'] && !$this->canEnable()) {
            $errors['enabled'][] = self::NOT_ABLE;
        }

        $items = $input['items'] ?? $had['items'];
        if (!is_array($items) || !array_is_list($items)) {
            $errors['items'][] = 'فهرست ایموجی‌ها را بفرستید.';
            $items = [];
        } elseif (count($items) > self::MAX) {
            // Refused whole, before a look at each one (a query apiece for the ones the bot may not know).
            throw new ValidationException($errors + ['items' => ['حداکثر ' . self::MAX . ' ایموجی.']]);
        }

        $known = array_column($had['items'], 'emoji_id');
        $mapped = array_flip(array_map(self::key(...), array_column($had['items'], 'emoji')));
        $kept = [];
        $keys = [];
        $ids = [];
        foreach ($items as $n => $item) {
            // A half-space typed after it is no part of the emoji (untailed()).
            $emoji = is_array($item) && is_string($item['emoji'] ?? null) ? self::untailed(trim($item['emoji']))[0] : '';
            $id = is_array($item) && is_string($item['emoji_id'] ?? null) ? trim($item['emoji_id']) : '';
            // One new to the map must be drawn as an emoji (drawnAsEmoji()); one kept stays as it was saved.
            if (!self::isOne($emoji) || (!isset($mapped[self::key($emoji)]) && !self::drawnAsEmoji($emoji))) {
                $errors["items.{$n}.emoji"][] = self::NOT_EMOJI;
            } elseif (isset($keys[self::key($emoji)])) {
                $errors["items.{$n}.emoji"][] = self::TWICE_EMOJI;
            }
            if (preg_match('/^\d{1,20}$/D', $id) !== 1) {
                $errors["items.{$n}.emoji_id"][] = self::ID_REFUSED;
            } elseif (isset($ids[$id])) {
                $errors["items.{$n}.emoji_id"][] = self::TWICE_ID;
            } elseif (!in_array($id, $known, true) && !CustomEmoji::query()->where('emoji_id', $id)->exists()) {
                $errors["items.{$n}.emoji_id"][] = self::NOT_KNOWN;
            }
            $keys[self::key($emoji)] = true;
            $ids[$id] = true;
            $kept[] = ['emoji' => $emoji, 'emoji_id' => $id];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($enabled === false && $kept === []) {
            $this->settings->forget(self::KEY);
        } else {
            $this->settings->set(self::KEY, ['enabled' => $enabled, 'items' => $kept]);
        }
    }

    /**
     * A wording of the shop's own (a text's template, its variables not filled yet) with its mapped plain emoji drawn
     * premium (rewrite()), while the map is on.
     */
    public function apply(string $wording, TextKind $kind): string
    {
        $map = $this->lookup();
        $found = [];

        return $map === [] ? $wording : self::rewrite($wording, $kind, $map, $found);
    }

    /**
     * A button's label, its variables filled (`$label`), with its leading emoji drawn premium when the wording itself starts
     * with it (`$wording`, unfilled) and the map has it — so the API client sends it as the button's icon (PlainWording) —;
     * unless a premium emoji already stands at the label's start or right after that emoji (a name's own, which is the
     * icon then), or the map is off.
     */
    public function label(string $wording, string $label): string
    {
        $leading = PremiumText::leadingEmoji($wording);
        $id = $leading === null ? null : ($this->lookup()[self::key($leading)] ?? null);
        if ($leading === null || $id === null || !str_starts_with($label, $leading)) {
            return $label;
        }
        $rest = substr($label, strlen($leading));
        $after = ltrim($rest, ' ');
        if (str_starts_with($after, '<tg-emoji') || trim($after) === '') {
            return $label;
        }

        return self::wrap($leading, $id) . $rest;
    }

    /**
     * The id the map gives the emoji a menu button's label starts with — null when it starts with none, the map is off,
     * or has no such emoji.
     */
    public function icon(string $label): ?string
    {
        $map = $this->lookup();
        $leading = PremiumText::leadingEmoji($label);

        return $map === [] || $leading === null ? null : ($map[self::key($leading)] ?? null);
    }

    /**
     * How many of `$wordings` (kind and template, as each text is sent) each of `$emojis` would be drawn premium in, by
     * the emoji's key().
     *
     * @param list<string> $emojis
     * @param list<array{TextKind, string}> $wordings
     * @return array<string, int>
     */
    public static function uses(array $emojis, array $wordings): array
    {
        $map = [];
        foreach ($emojis as $emoji) {
            $map[self::key($emoji)] = '0';
        }
        $counts = array_fill_keys(array_keys($map), 0);
        foreach ($wordings as [$kind, $wording]) {
            $found = [];
            self::rewrite($wording, $kind, $map, $found);
            foreach (array_keys($found) as $key) {
                $counts[$key]++;
            }
        }

        return $counts;
    }

    /**
     * The plain emoji the shop's texts carry where the map would draw them premium — every one in `$wordings` (kind and
     * template, as each text is sent; how many carry it) and the leading emoji of each menu button `$menu` lists (how many
     * buttons; KeyboardLayout::iconable()) —, each with the premium emoji `$items` swaps it for: the most carried first,
     * then as met; after them the map's emoji nothing carries any more, in its order. Each is shown as first met, the form
     * with its variation selector (❤️ over ❤) preferred.
     *
     * @param list<array{TextKind, string}> $wordings
     * @param list<string> $menu
     * @param list<array{emoji: string, emoji_id: string}> $items
     * @return list<array{emoji: string, texts: int, menu: int, emoji_id: ?string}>
     */
    public static function inventory(array $wordings, array $menu, array $items): array
    {
        /** @var array<string, array{emoji: string, texts: int, menu: int, emoji_id: ?string}> $byKey */
        $byKey = [];
        foreach ($wordings as [$kind, $wording]) {
            $found = [];
            self::rewrite($wording, $kind, null, $found);
            foreach ($found as $emoji) {
                self::tally($byKey, $emoji, 'texts');
            }
        }
        foreach ($menu as $emoji) {
            // One ending in a half-space gets no icon (icon() finds no emoji of that key): not counted.
            if (self::untailed($emoji)[1] === '') {
                self::tally($byKey, $emoji, 'menu');
            }
        }
        // Signs that are no emoji (━, ★) left out — unless the map has one, saved before this rule, which is drawn —; most
        // carried first, usort() keeping the order met among equals.
        $mapped = array_flip(array_map(self::key(...), array_column($items, 'emoji')));
        $rows = array_values(array_filter($byKey, static fn(array $row): bool => self::drawnAsEmoji($row['emoji']) || isset($mapped[self::key($row['emoji'])])));
        usort($rows, static fn(array $a, array $b): int => ($b['texts'] + $b['menu']) <=> ($a['texts'] + $a['menu']));

        $at = [];
        foreach ($rows as $n => $row) {
            $at[self::key($row['emoji'])] = $n;
        }
        foreach ($items as $item) {
            $key = self::key($item['emoji']);
            if (isset($at[$key])) {
                $rows[$at[$key]]['emoji_id'] = $item['emoji_id'];
            } else {
                $rows[] = ['emoji' => $item['emoji'], 'texts' => 0, 'menu' => 0, 'emoji_id' => $item['emoji_id']];
            }
        }

        return $rows;
    }

    /**
     * One more text or menu button (`$where`) carrying `$emoji`, counted under its key(), shown with its variation
     * selector once any carries it so.
     *
     * @param array<string, array{emoji: string, texts: int, menu: int, emoji_id: ?string}> $rows
     * @param 'texts'|'menu' $where
     */
    private static function tally(array &$rows, string $emoji, string $where): void
    {
        $key = self::key($emoji);
        $row = $rows[$key] ?? ['emoji' => $emoji, 'texts' => 0, 'menu' => 0, 'emoji_id' => null];
        if (!str_contains($row['emoji'], "\u{FE0F}") && str_contains($emoji, "\u{FE0F}")) {
            $row['emoji'] = $emoji;
        }
        $row[$where]++;
        $rows[$key] = $row;
    }

    /** An emoji as the map compares it: without the variation selectors (❤ and ❤️ are one heart). */
    public static function key(string $emoji): string
    {
        return str_replace(["\u{FE0F}", "\u{FE0E}"], '', $emoji);
    }

    /** Whether `$text` is one emoji as a reader sees it. */
    public static function isOne(string $text): bool
    {
        return $text !== '' && PremiumText::leadingEmoji($text) === $text;
    }

    /**
     * Whether an emoji (one, TelegramHtml::isEmoji()'s) is drawn as one — not a sign counted among them by its code point
     * alone: a line's ━, ★, ✓, © (DRAWN). Only these are listed and newly mapped: Telegram wants an emoji in a premium
     * emoji's tag.
     */
    public static function drawnAsEmoji(string $emoji): bool
    {
        return preg_match(self::DRAWN, $emoji) === 1;
    }

    /**
     * The map while it is on: an emoji's key() → its premium emoji's id; empty while off or empty.
     *
     * @return array<string, string>
     */
    private function lookup(): array
    {
        $stored = $this->stored();
        if (!$stored['enabled']) {
            return [];
        }
        $map = [];
        foreach ($stored['items'] as $item) {
            $map[self::key($item['emoji'])] = $item['emoji_id'];
        }

        return $map;
    }

    /**
     * `$wording` with each plain emoji `$map` has drawn premium — in a text Telegram reads as HTML, every one in its words
     * outside a premium emoji, code, a link or a date (TelegramHtml::mapWords()); in a button's label, the leading one
     * alone, with words after it and no premium emoji of its own there (label() decides, the label filled); a popup,
     * which draws none, as it is. `$found` gets each emoji drawn by its key(), as met (the form with its variation
     * selector preferred). A null `$map` finds every emoji it would draw and leaves `$wording` as it is.
     *
     * @param array<string, string>|null $map
     * @param array<string, string> $found
     */
    private static function rewrite(string $wording, TextKind $kind, ?array $map, array &$found): string
    {
        $met = static function (string $emoji) use (&$found): void {
            $key = self::key($emoji);
            if (!isset($found[$key]) || (!str_contains($found[$key], "\u{FE0F}") && str_contains($emoji, "\u{FE0F}"))) {
                $found[$key] = $emoji;
            }
        };
        if ($kind === TextKind::Button) {
            $leading = PremiumText::leadingEmoji($wording);
            $after = $leading === null ? '' : ltrim(substr($wording, strlen($leading)), ' ');
            // One ending in a half-space is left, as label() leaves it: its key is no emoji's.
            if ($leading !== null && self::untailed($leading)[1] === '' && ($map === null || isset($map[self::key($leading)])) && trim($after) !== '' && !str_starts_with($after, '<tg-emoji')) {
                $met($leading);
            }

            return $wording;
        }
        if (!$kind->html()) {
            return $wording;
        }

        return TelegramHtml::mapWords($wording, static function (string $words) use ($map, $met): string {
            return (string) preg_replace_callback('/\X/u', static function (array $grapheme) use ($map, $met): string {
                [$emoji, $tail] = self::untailed($grapheme[0]);
                $key = self::key($emoji);
                if (($map !== null && !isset($map[$key])) || !TelegramHtml::isEmoji($emoji)) {
                    return $grapheme[0];
                }
                $met($emoji);

                return ($map === null ? $emoji : self::wrap($emoji, $map[$key])) . $tail;
            }, $words);
        }, self::SKIP);
    }

    /**
     * A grapheme and the zero-width joiners or non-joiners it ends in, apart — a half-space typed right after an emoji
     * belongs to the reading, so `\X` takes it in, but it is no part of the emoji: kept outside its tag.
     *
     * @return array{string, string}
     */
    private static function untailed(string $grapheme): array
    {
        $bare = (string) preg_replace('/[\x{200C}\x{200D}]+$/u', '', $grapheme);

        return $bare === '' ? [$grapheme, ''] : [$bare, substr($grapheme, strlen($bare))];
    }

    private static function wrap(string $emoji, string $id): string
    {
        return '<tg-emoji emoji-id="' . $id . '">' . $emoji . '</tg-emoji>';
    }
}
