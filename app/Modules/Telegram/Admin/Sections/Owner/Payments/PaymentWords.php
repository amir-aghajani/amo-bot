<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\Sections\Catalog\CatalogFmt;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Persian;

/**
 * How the payment methods' screens say a method — the panel's words (payment-methods.tsx, kinds.ts, method-summary.tsx,
 * gateways/index.tsx): how it settles, the amounts it is offered for, a card method's cards with today's take against
 * each one's cap, why a method is offered to nobody now, and a driver's description in one line.
 */
final class PaymentWords
{
    /** How a method settles (KIND_LABELS). */
    public const KINDS = ['instant' => 'تسویه آنی', 'manual' => 'تایید دستی', 'online' => 'درگاه آنلاین'];

    /** The list's title: the shop menu's «روش‌های پرداخت». */
    public const TITLE = Icons::METHODS . ' روش‌های پرداخت';

    /** A card method's line while the shop takes card payments in support's private chat. */
    private const TO_SUPPORT = 'پرداخت کارت به کارت به پیوی پشتیبانی می‌رود؛ کارت‌ها به مشتری نشان داده نمی‌شوند و سقف روزانه‌شان پر نمی‌شود.';

    /** The longest one-line description of a driver (gist()). */
    private const GIST_MOST = 90;

    /**
     * The path of a screen under the methods' list — «مدیریت › فروشگاه › روش‌های پرداخت» and the sections `$more`.
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return CatalogFmt::under(Fmt::bare(self::TITLE), ...$more);
    }

    /**
     * How the method settles; «درایور نصب نیست» for one whose driver is gone.
     *
     * @param array<string, mixed> $method
     */
    public static function kind(array $method): string
    {
        return is_string($method['kind'] ?? null) ? (self::KINDS[$method['kind']] ?? $method['kind']) : 'درایور نصب نیست';
    }

    /**
     * The amounts a method is offered for, short: «۱۰٬۰۰۰ تا ۵۰۰٬۰۰۰ تومان», «از …», «تا …», «هر مبلغی».
     *
     * @param array<string, mixed> $method
     */
    public static function bounds(array $method): string
    {
        $min = is_scalar($method['min_amount'] ?? null) ? (string) $method['min_amount'] : null;
        $max = is_scalar($method['max_amount'] ?? null) ? (string) $method['max_amount'] : null;

        return match (true) {
            $min !== null && $max !== null => Fmt::money($min) . ' تا ' . Fmt::money($max),
            $min !== null => 'از ' . Fmt::money($min) . ' به بالا',
            $max !== null => 'تا ' . Fmt::money($max),
            default => 'هر مبلغی',
        };
    }

    /**
     * Why a method switched on is offered to no customer now (plain text): its driver gone, a card method with no card
     * left to show; null when it is offered (or off: its chip says so).
     *
     * @param array<string, mixed> $method
     */
    public static function unoffered(array $method): ?string
    {
        if (!Json::on($method, 'enabled')) {
            return null;
        }
        if (($method['kind'] ?? null) === null) {
            return 'درایورش نصب نیست؛ به مشتری پیشنهاد نمی‌شود.';
        }
        if (($method['driver'] ?? null) !== 'manual' || Json::on($method, 'to_support')) {
            return null;
        }
        $cards = array_values(array_filter((array) ($method['cards'] ?? []), is_array(...)));
        $on = array_filter($cards, static fn(array $card): bool => Json::on($card, 'enabled'));

        return match (true) {
            $cards === [] => 'کارتی ندارد؛ به مشتری پیشنهاد نمی‌شود.',
            $on === [] => 'همه کارت‌ها غیرفعال‌اند؛ به مشتری پیشنهاد نمی‌شود.',
            array_filter($on, static fn(array $card): bool => !self::full($card)) === [] => 'سقف روزانه کارت‌های فعال پر شده؛ تا فردا به مشتری پیشنهاد نمی‌شود.',
            default => null,
        };
    }

    /**
     * What a method's card says of how it works besides its facts (HTML lines): an online gateway's own word, a card
     * method's automatic approval.
     *
     * @param array<string, mixed> $method
     * @return array<string, string>
     */
    public static function facts(array $method): array
    {
        $facts = [];
        if (is_string($method['summary'] ?? null) && $method['summary'] !== '' && ($method['driver'] ?? null) !== 'manual') {
            $facts['تنظیم'] = self::summary($method['summary']);
        }
        if (($method['driver'] ?? null) === 'manual') {
            $after = (int) ($method['config']['auto_approve_after'] ?? 0);
            $facts['تایید خودکار رسید'] = $after > 0 ? 'بعد از ' . Fmt::e(Persian::minutes($after)) : Words::OFF;
        } elseif (($method['kind'] ?? null) === 'online') {
            $facts['پرداخت'] = 'در صفحه ' . Fmt::e((string) ($method['driver_label'] ?? '')) . '، بدون رسید';
        }

        return $facts;
    }

    /**
     * A card method's cards, a line each — switched off, or its cap taken today, said; while card payments go to
     * support's private chat, that instead.
     *
     * @param array<mixed> $cards
     * @return list<string>
     */
    public static function cards(array $cards, bool $toSupport): array
    {
        $cards = array_values(array_filter($cards, is_array(...)));
        $lines = [];
        foreach ($cards as $card) {
            $line = self::dot($card, $toSupport) . ' ' . self::summary((string) ($card['summary'] ?? ''));
            if (!$toSupport && Json::on($card, 'enabled') && is_scalar($card['daily_cap'] ?? null)) {
                $line .= ' — امروز ' . Fmt::money((string) ($card['today'] ?? '0')) . ' از ' . Fmt::money((string) $card['daily_cap']);
            }
            $lines[] = $line;
        }
        if ($toSupport) {
            $lines[] = 'ℹ️ ' . self::TO_SUPPORT;
        }

        return $lines;
    }

    /**
     * A card's chip: 🟢 on, ⏸ off, ⚠️ its cap taken today.
     *
     * @param array<string, mixed> $card
     */
    public static function dot(array $card, bool $toSupport = false): string
    {
        return CatalogFmt::dot(Json::on($card, 'enabled'), !$toSupport && self::full($card));
    }

    /**
     * A driver's description in one line: what comes before its first «:», «؛» or «.» — what it is, not how to set it
     * up —, cut to fit a line.
     */
    public static function gist(string $description): string
    {
        $first = preg_split('/[:؛.]\s/u', $description . ' ', 2)[0] ?? $description;

        return Fmt::cut(trim($first), self::GIST_MOST);
    }

    /**
     * A one-line summary of what the customer pays to («6037 •••• •••• 1119 · امیر رضایی · ملت»): each part in its own
     * direction — the masked card left to right, the words as written.
     */
    public static function summary(string $summary): string
    {
        return implode(' · ', array_map(static fn(string $part): string => preg_match('/\p{L}/u', $part) === 1 ? Fmt::e($part) : Fmt::ltr($part), explode(' · ', $summary)));
    }

    /**
     * Whether the card took its whole cap today.
     *
     * @param array<string, mixed> $card
     */
    public static function full(array $card): bool
    {
        return is_scalar($card['daily_cap'] ?? null) && (float) ($card['today'] ?? 0) >= (float) $card['daily_cap'];
    }
}
