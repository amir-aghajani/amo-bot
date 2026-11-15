<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Support\Persian;

/**
 * «سرویس دلخواه»'s prices as its settings hold them (the bot settings' `custom_services` group, read from the panel's
 * API): each quantity's tiers — every unit at the rate of the tier the quantity falls into —, the bounds a customer
 * builds within (the least never below the first tier), the amounts offered as buttons, and the price of what a
 * customer builds, the GB's and the days' added (the panel's calculator, Catalog\Services\CustomServices' rule).
 */
final class CustomPricing
{
    /** Each quantity in the panel's words, by the key its fields are named after. */
    public const QUANTITIES = [
        'traffic' => ['title' => '📶 حجم', 'unit' => 'گیگابایت', 'unitPrice' => 'قیمت هر گیگابایت', 'about' => 'گیگابایت‌هایی که مشتری برای سرویسش انتخاب می‌کند.'],
        'days' => ['title' => '📅 مدت', 'unit' => 'روز', 'unitPrice' => 'قیمت هر روز', 'about' => 'روزهایی که سرویس از اولین اتصال کار می‌کند.'],
    ];

    /**
     * The quantity's tiers, the smallest start first.
     *
     * @param array<string, mixed> $settings
     * @return list<array{from: int, price: int}>
     */
    public static function tiers(array $settings, string $quantity): array
    {
        $tiers = [];
        foreach ((array) ($settings["custom_{$quantity}_tiers"] ?? []) as $tier) {
            if (is_array($tier) && is_numeric($tier['from'] ?? null) && is_numeric($tier['price'] ?? null)) {
                $tiers[] = ['from' => (int) $tier['from'], 'price' => (int) round((float) $tier['price'])];
            }
        }
        usort($tiers, static fn(array $a, array $b): int => $a['from'] <=> $b['from']);

        return $tiers;
    }

    /**
     * The tiers as lines: «از ۵۰ گیگابایت: هر گیگابایت ۲٬۵۰۰ تومان».
     *
     * @param list<array{from: int, price: int}> $tiers
     * @return list<string>
     */
    public static function tierLines(array $tiers, string $quantity): array
    {
        $unit = self::QUANTITIES[$quantity]['unit'];
        if ($tiers === []) {
            return ['بدون پله قیمت، سرویس دلخواه فروخته نمی‌شود.'];
        }

        return array_map(static fn(array $tier): string => '• از ' . Persian::number($tier['from']) . " {$unit}: هر {$unit} " . Fmt::money($tier['price']), $tiers);
    }

    /**
     * What a customer may build of the quantity: from the least set — never below the first tier — to the most.
     *
     * @param array<string, mixed> $settings
     * @return array{0: int, 1: int}
     */
    public static function bounds(array $settings, string $quantity): array
    {
        $tiers = self::tiers($settings, $quantity);

        return [max((int) ($settings["custom_{$quantity}_min"] ?? 1), $tiers[0]['from'] ?? 1), (int) ($settings["custom_{$quantity}_max"] ?? 0)];
    }

    /**
     * The amounts offered as buttons as the bot offers them, and those it leaves out — outside what may be built — in
     * words.
     *
     * @param array<string, mixed> $settings
     */
    public static function buttons(array $settings, string $quantity): string
    {
        $unit = self::QUANTITIES[$quantity]['unit'];
        $amounts = array_values(array_unique(array_map(intval(...), (array) ($settings["custom_{$quantity}_options"] ?? []))));
        sort($amounts);
        if ($amounts === []) {
            return 'دکمه‌ای ندارد؛ مشتری عدد را خودش می‌نویسد.';
        }
        [$least, $most] = self::bounds($settings, $quantity);
        $within = static fn(int $amount): bool => $amount >= $least && $amount <= $most;
        $shown = array_filter($amounts, $within);
        $left = array_filter($amounts, static fn(int $amount): bool => !$within($amount));
        $words = $shown === [] ? 'دکمه‌ای نشان داده نمی‌شود' : implode('، ', array_map(Persian::number(...), $shown)) . " {$unit}";
        if ($left !== [] && self::tiers($settings, $quantity) !== []) {
            $words .= '؛ ' . implode('، ', array_map(Persian::number(...), $left)) . ' نشان داده نمی‌شود، چون مشتری بین ' . Persian::number($least) . ' تا ' . Persian::number($most) . " {$unit} می‌سازد";
        }

        return $words;
    }

    /**
     * The price of `$amount` of the quantity — its tier's rate a unit, and the whole —, or why there is none.
     *
     * @param array<string, mixed> $settings
     * @return array{unit: int, total: int}|string
     */
    public static function quote(array $settings, string $quantity, int $amount): array|string
    {
        $tiers = self::tiers($settings, $quantity);
        $unit = self::QUANTITIES[$quantity]['unit'];
        if ($tiers === []) {
            return 'هنوز پله قیمتی ندارد؛ بدون آن فروخته نمی‌شود.';
        }
        [$least, $most] = self::bounds($settings, $quantity);
        $tier = null;
        foreach ($tiers as $candidate) {
            if ($amount >= $candidate['from']) {
                $tier = $candidate;
            }
        }
        if ($tier === null || $amount < $least || $amount > $most) {
            return 'مشتری بین ' . Persian::number($least) . ' تا ' . Persian::number($most) . " {$unit} می‌سازد.";
        }

        return ['unit' => $tier['price'], 'total' => $tier['price'] * $amount];
    }
}
