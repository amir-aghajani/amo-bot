<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Telegram\Messages;
use App\Modules\Telegram\Texts\PremiumText;
use App\Support\Persian;
use App\Support\Traffic;

/**
 * How the catalogue's screens say a plan's things — its price («رایگان» for none to pay), traffic, term and devices, its
 * category, its button and badge, whose extras prices it sells at, in the panel's words — and the little they share: the
 * shop menu they are under, a row's chip, a name's premium emoji (whether it has one, the one typed), a row moved among
 * its neighbours.
 */
final class CatalogFmt
{
    /** The shop menu the catalogue is in («📦 فروشگاه», the home's): its screen, its lists' way back. */
    public const HUB = 'hs';

    /** The shop menu's title. */
    public const SHOP = Icons::SHOP . ' فروشگاه';

    /** The labels a plan's card may wear in the mini app and on the website (PlanBadge::label()). */
    public const BADGES = ['bestseller' => 'پرفروش‌ترین', 'recommended' => 'پیشنهاد ما', 'vip' => "\u{2068}VIP\u{2069}"];

    /** A plan's button colour in the bot's plan list: the client's own, or one of Telegram's styles. */
    public const STYLES = ['' => 'پیش‌فرض (رنگ خودکار)', 'primary' => 'اصلی (آبی)', 'success' => 'موفقیت (سبز)', 'danger' => 'خطر (قرمز)'];

    /** How many buttons share a plan's button's row in the bot's plan list. */
    public const ROWS = [1 => 'یک ردیف کامل', 2 => 'دو در ردیف', 3 => 'سه در ردیف'];

    /**
     * The path of a screen under the shop menu — «مدیریت › فروشگاه» and the sections `$more` between.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, Fmt::bare(self::SHOP), ...array_values($more)];
    }

    /**
     * A row's state as a button's leading chip (its emoji alone, the label being short): active 🟢, inactive ⏸ — and ⚠️
     * for one active that the customer does not see now.
     */
    public static function dot(bool $active, bool $unseen = false): string
    {
        return self::emoji(match (true) {
            !$active => Words::INACTIVE,
            $unseen => '⚠️',
            default => Words::ACTIVE,
        });
    }

    /** A chip's emoji, its word left out: «🟢 فعال» → «🟢». */
    public static function emoji(string $chip): string
    {
        return explode(' ', $chip)[0];
    }

    /** What a plan costs: its amount, «رایگان» for 0, «—» for none. */
    public static function price(string|int|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return Fmt::NONE;
        }

        return (float) $amount == 0.0 ? 'رایگان' : Fmt::money($amount);
    }

    /** A plan's traffic in gigabytes as it holds them (decimals allowed): «۳۰ گیگابایت», «نامحدود» for 0. */
    public static function traffic(string|int|float|null $gigabytes): string
    {
        return Fmt::quota(Traffic::bytesOfGb($gigabytes ?? 0));
    }

    /** Devices at once: «۲ کاربر», «نامحدود» for 0. */
    public static function devices(int|string|null $count): string
    {
        return Messages::devices((int) $count);
    }

    /**
     * A plan's category as its card says it (plain text): its name, «(غیرفعال)» after it while customers do not see it.
     *
     * @param array<string, mixed> $category
     */
    public static function category(array $category): string
    {
        return (string) ($category['name'] ?? '') . ((bool) ($category['is_active'] ?? true) ? '' : ' (' . Fmt::bare(Words::INACTIVE) . ')');
    }

    /** Whose prices a plan's extras are sold at: «قیمت‌های این پلن» for its own list, else «قیمت‌های فروشگاه». */
    public static function extras(bool $own): string
    {
        return $own ? 'قیمت‌های این پلن' : 'قیمت‌های فروشگاه';
    }

    /** Whether a name has its premium emoji: «✨ دارد» / «ندارد». */
    public static function hasPremium(bool $has): string
    {
        return $has ? '✨ دارد' : 'ندارد';
    }

    /** An amount the API wrote ("120000.00") as a form starts from it ("120000"); null for none. */
    public static function amount(mixed $amount): ?string
    {
        if (!is_scalar($amount) || $amount === '') {
            return null;
        }
        $text = (string) $amount;

        return preg_match('/^(-?\d+)(?:\.0+)?$/', $text, $match) === 1 ? $match[1] : $text;
    }

    /**
     * The premium emoji in what was typed — the first one, with the plain emoji it stands for and whether it is the
     * first thing in the text —; null for none.
     *
     * @return array{id: string, emoji: string, leading: bool}|null
     */
    public static function premium(Answer $answer): ?array
    {
        $message = $answer->message();
        $text = (string) ($message['text'] ?? $message['caption'] ?? '');
        $entities = $message['entities'] ?? $message['caption_entities'] ?? [];
        foreach (is_array($entities) ? $entities : [] as $entity) {
            if (!is_array($entity) || ($entity['type'] ?? null) !== 'custom_emoji' || !is_string($entity['custom_emoji_id'] ?? null)) {
                continue;
            }
            $offset = (int) ($entity['offset'] ?? 0);
            // Telegram counts in UTF-16 code units.
            $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
            $emoji = (string) mb_convert_encoding(substr($utf16, $offset * 2, (int) ($entity['length'] ?? 0) * 2), 'UTF-8', 'UTF-16LE');
            $before = (string) mb_convert_encoding(substr($utf16, 0, $offset * 2), 'UTF-8', 'UTF-16LE');

            return ['id' => $entity['custom_emoji_id'], 'emoji' => $emoji, 'leading' => trim($before) === ''];
        }

        return null;
    }

    /**
     * A name's premium emoji once it is typed anew: the one it starts with, typed; else the one it had while the name
     * still starts with an emoji (the bot draws it in that emoji's place); none for a name without one.
     */
    public static function nameEmoji(Answer $answer, string $name, ?string $had): ?string
    {
        $premium = self::premium($answer);
        if ($premium !== null && $premium['leading']) {
            return $premium['id'];
        }

        return PremiumText::leadingEmoji($name) === null ? null : $had;
    }

    /** `$name` with `$emoji` as its leading emoji: in place of the one it starts with, or before it (the panel's own). */
    public static function withLeading(string $name, string $emoji): string
    {
        $name = ltrim($name);
        $leading = PremiumText::leadingEmoji($name);

        return $emoji . ' ' . ltrim($leading === null ? $name : substr($name, strlen($leading)));
    }

    /**
     * The row of `$id` among `$rows` (a list the panel answered whole); null for none.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function find(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The ids in their order with `$id` moved one place among the rows `$same` says are its kind (a plan among its
     * category's): swapped with its neighbour there, the others where they were; null when it has none that way.
     *
     * @param list<array<string, mixed>> $rows In their order
     * @param \Closure(array<string, mixed>): bool $same
     * @return list<int>|null
     */
    public static function moved(array $rows, int $id, int $by, \Closure $same): ?array
    {
        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows);
        $at = array_search($id, $ids, true);
        if ($at === false) {
            return null;
        }
        for ($i = $at + $by; $i >= 0 && $i < count($rows); $i += $by) {
            if ($same($rows[$i])) {
                [$ids[$at], $ids[$i]] = [$ids[$i], $ids[$at]];

                return $ids;
            }
        }

        return null;
    }

    /** «۳ از ۸»: a row's place in its list. */
    public static function place(int $at, int $of): string
    {
        return Persian::number($at) . ' از ' . Persian::number($of);
    }
}
