<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Payments;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Support\Persian;

/**
 * How the payment methods' screens say a method — the panel's words (payment-methods.tsx, kinds.ts, method-summary.tsx,
 * gateways/index.tsx): how it settles, the amounts it is offered for, a card method's cards with today's take against
 * each one's cap, and what the list says beside a method (a card's automatic approval, an online gateway's own word).
 */
final class PaymentWords
{
    /** How a method settles (KIND_LABELS). */
    public const KINDS = ['instant' => 'تسویه آنی', 'manual' => 'تایید دستی', 'online' => 'درگاه آنلاین'];

    /** A card method's line while the shop takes card payments in support's private chat. */
    private const TO_SUPPORT = 'پرداخت کارت به کارت به پیوی پشتیبانی می‌رود؛ کارت‌ها به مشتری نشان داده نمی‌شوند و سقف روزانه‌شان پر نمی‌شود.';

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
     * The amounts a method is offered for, as the customer is told them; null while it takes any.
     *
     * @param array<string, mixed> $method
     */
    public static function bounds(array $method): ?string
    {
        $min = is_scalar($method['min_amount'] ?? null) ? (string) $method['min_amount'] : null;
        $max = is_scalar($method['max_amount'] ?? null) ? (string) $method['max_amount'] : null;

        return match (true) {
            $min !== null && $max !== null => 'برای مبلغ‌های بین ' . Fmt::money($min) . ' و ' . Fmt::money($max),
            $min !== null => 'برای مبلغ‌های از ' . Fmt::money($min) . ' به بالا',
            $max !== null => 'برای مبلغ‌های تا ' . Fmt::money($max),
            default => null,
        };
    }

    /**
     * What the method's line says of it besides its name (HTML lines): its summary, a card method's cards, its note, its
     * bounds — or that no checkout offers it, its driver gone.
     *
     * @param array<string, mixed> $method
     * @return list<string>
     */
    public static function details(array $method): array
    {
        if (($method['kind'] ?? null) === null) {
            return ['⚠️ به مشتری پیشنهاد نمی‌شود؛ درایورش نصب نیست.'];
        }
        $lines = [];
        if (is_string($method['summary'] ?? null) && $method['summary'] !== '') {
            $lines[] = self::summary($method['summary']);
        }
        if (($method['driver'] ?? null) === 'manual' && is_array($method['cards'] ?? null)) {
            $lines = [...$lines, ...self::cards($method['cards'], Json::on($method, 'to_support'))];
            $after = (int) ($method['config']['auto_approve_after'] ?? 0);
            if ($after > 0) {
                $lines[] = '⏱ تایید خودکار بعد از ' . Persian::minutes($after);
            }
        } elseif (($method['kind'] ?? null) === 'online') {
            $lines[] = '☑️ پرداخت در صفحه ' . Fmt::e((string) ($method['driver_label'] ?? '')) . '، بدون رسید و با تایید خود درگاه';
        }
        $bounds = self::bounds($method);
        if ($bounds !== null) {
            $lines[] = $bounds;
        }

        return $lines;
    }

    /**
     * A card method's cards, a line each — switched off, or its cap taken today, said —, and when none is left to show,
     * that the method is offered to nobody; while card payments go to support's private chat, that instead.
     *
     * @param array<mixed> $cards
     * @return list<string>
     */
    public static function cards(array $cards, bool $toSupport): array
    {
        $cards = array_values(array_filter($cards, is_array(...)));
        if ($cards === []) {
            return [$toSupport ? self::TO_SUPPORT : '⚠️ کارتی ندارد؛ به مشتری پیشنهاد نمی‌شود.'];
        }
        $lines = [];
        $on = array_filter($cards, static fn(array $card): bool => Json::on($card, 'enabled'));
        foreach ($cards as $card) {
            $line = '💳 ' . self::summary((string) ($card['summary'] ?? ''));
            if (($card['enabled'] ?? false) !== true) {
                $line .= ' (غیرفعال)';
            } elseif (!$toSupport && is_scalar($card['daily_cap'] ?? null)) {
                $line .= ' — امروز ' . Fmt::money((string) ($card['today'] ?? '0')) . ' از ' . Fmt::money((string) $card['daily_cap']) . (self::full($card) ? '؛ پر شده' : '');
            }
            $lines[] = $line;
        }
        $lines[] = match (true) {
            $toSupport => self::TO_SUPPORT,
            $on === [] => '⚠️ همه کارت‌ها غیرفعال‌اند؛ به مشتری پیشنهاد نمی‌شود.',
            array_filter($on, static fn(array $card): bool => !self::full($card)) === [] => '⚠️ سقف روزانه کارت‌های فعال پر شده؛ تا فردا به مشتری پیشنهاد نمی‌شود.',
            default => '',
        };

        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
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
    private static function full(array $card): bool
    {
        return is_scalar($card['daily_cap'] ?? null) && (float) ($card['today'] ?? 0) >= (float) $card['daily_cap'];
    }
}
