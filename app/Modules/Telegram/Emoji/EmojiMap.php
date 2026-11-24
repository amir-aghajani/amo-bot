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
        $kept = [];
        $keys = [];
        $ids = [];
        foreach ($items as $n => $item) {
            $emoji = is_array($item) && is_string($item['emoji'] ?? null) ? trim($item['emoji']) : '';
            $id = is_array($item) && is_string($item['emoji_id'] ?? null) ? trim($item['emoji_id']) : '';
            if (!self::isOne($emoji)) {
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
     * alone (label() decides, the label filled); a popup, which draws none, as it is. `$found` gets the key() of each
     * emoji drawn.
     *
     * @param array<string, string> $map
     * @param array<string, true> $found
     */
    private static function rewrite(string $wording, TextKind $kind, array $map, array &$found): string
    {
        if ($kind === TextKind::Button) {
            $leading = PremiumText::leadingEmoji($wording);
            if ($leading !== null && isset($map[self::key($leading)])) {
                $found[self::key($leading)] = true;
            }

            return $wording;
        }
        if (!$kind->html()) {
            return $wording;
        }

        return TelegramHtml::mapWords($wording, static function (string $words) use ($map, &$found): string {
            return (string) preg_replace_callback('/\X/u', static function (array $grapheme) use ($map, &$found): string {
                $key = self::key($grapheme[0]);
                if (!isset($map[$key]) || !TelegramHtml::isEmoji($grapheme[0])) {
                    return $grapheme[0];
                }
                $found[$key] = true;

                return self::wrap($grapheme[0], $map[$key]);
            }, $words);
        }, self::SKIP);
    }

    private static function wrap(string $emoji, string $id): string
    {
        return '<tg-emoji emoji-id="' . $id . '">' . $emoji . '</tg-emoji>';
    }
}
