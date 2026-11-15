<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Ui\Json;

/**
 * A plan as the bot's plan form holds it while it is being made or edited (PlanEditScreen keeps it in the chat): the
 * panel's request, field by field — the prices as typed Toman, the term in hours, the server entries, the plan's own
 * extras price list (null: the shop's) — and what it was opened from. Sent whole in one save, as the panel's form is.
 */
final class PlanDraft
{
    /** The panel's form tabs, each its fields' home: a refused field points at its tab. */
    public const TABS = ['details' => '🏷 مشخصات', 'quota' => '💰 قیمت و سهمیه', 'servers' => '🖥 سرورها', 'extras' => '➕ خرید اضافه'];

    /** Each field in the panel's words. */
    public const LABELS = [
        'name' => 'نام پلن',
        'emoji_id' => 'ایموجی پرمیوم نام',
        'category_id' => 'دسته',
        'description' => 'توضیحات',
        'checkout_note' => 'یادداشت پیش‌فاکتور',
        'button_style' => 'رنگ دکمه',
        'button_row' => 'پهنای دکمه',
        'badge' => 'برچسب',
        'is_active' => 'قابل خرید',
        'price' => 'قیمت',
        'first_purchase_price' => 'قیمت خرید اول',
        'agent_price' => 'قیمت نماینده',
        'traffic_gb' => 'حجم',
        'duration_hours' => 'مدت اعتبار',
        'ip_limit' => 'تعداد کاربر',
        'servers' => 'سرورها',
        'extras' => 'خرید اضافه',
    ];

    /** The tab of each field the server may refuse (the panel's SECTION_OF); anything else is the servers'. */
    private const TAB_OF = [
        'name' => 'details', 'emoji_id' => 'details', 'category_id' => 'details', 'description' => 'details', 'checkout_note' => 'details',
        'button_style' => 'details', 'button_row' => 'details', 'badge' => 'details', 'is_active' => 'details',
        'price' => 'quota', 'first_purchase_price' => 'quota', 'agent_price' => 'quota', 'traffic_gb' => 'quota', 'duration_hours' => 'quota',
        'ip_limit' => 'quota', 'duration_days' => 'quota',
        'servers' => 'servers',
        'extras' => 'extras',
    ];

    /** A plan's term the form starts from: 30 days. */
    private const TERM = 720;

    /**
     * A new plan's draft: nothing typed, sold once saved, one device for 30 days, its button a whole row.
     *
     * @return array<string, mixed>
     */
    public static function fresh(): array
    {
        return [
            'name' => '',
            'emoji_id' => null,
            'category_id' => 0,
            'description' => '',
            'checkout_note' => '',
            'button_style' => null,
            'button_row' => 1,
            'badge' => null,
            'is_active' => true,
            'price' => null,
            'first_purchase_price' => null,
            'agent_price' => null,
            'traffic_gb' => null,
            'duration_hours' => self::TERM,
            'ip_limit' => 1,
            'servers' => [],
            'extras' => null,
            'extras_kept' => null,
        ];
    }

    /**
     * A plan as the panel lists it, as its form opens it.
     *
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public static function of(array $plan): array
    {
        $category = is_array($plan['category'] ?? null) ? $plan['category'] : null;
        $servers = [];
        foreach (Json::part($plan, 'servers') as $entry) {
            if (!is_array($entry) || !is_array($entry['server'] ?? null)) {
                continue;
            }
            $all = (bool) ($entry['all_inbounds'] ?? false);
            $servers[] = [
                'server_id' => (int) ($entry['server']['id'] ?? 0),
                'all_inbounds' => $all,
                'inbound_ids' => $all ? [] : array_values(array_map(static fn(mixed $inbound): int => is_array($inbound) ? (int) ($inbound['id'] ?? 0) : 0, Json::part($entry, 'inbounds'))),
            ];
        }
        $extras = is_array($plan['extras'] ?? null) ? self::extrasOf($plan['extras']) : null;

        return [
            'name' => (string) ($plan['name'] ?? ''),
            'emoji_id' => is_string($plan['emoji_id'] ?? null) ? $plan['emoji_id'] : null,
            'category_id' => $category === null ? 0 : (int) ($category['id'] ?? 0),
            'description' => (string) ($plan['description'] ?? ''),
            'checkout_note' => (string) ($plan['checkout_note'] ?? ''),
            'button_style' => is_string($plan['button_style'] ?? null) ? $plan['button_style'] : null,
            'button_row' => (int) ($plan['button_row'] ?? 1),
            'badge' => is_string($plan['badge'] ?? null) ? $plan['badge'] : null,
            'is_active' => (bool) ($plan['is_active'] ?? true),
            'price' => CatalogFmt::amount($plan['price'] ?? null),
            'first_purchase_price' => CatalogFmt::amount($plan['first_purchase_price'] ?? null),
            'agent_price' => CatalogFmt::amount($plan['agent_price'] ?? null),
            'traffic_gb' => is_numeric($plan['traffic_gb'] ?? null) ? self::decimal($plan['traffic_gb']) : null,
            'duration_hours' => (int) ($plan['duration_hours'] ?? self::TERM),
            'ip_limit' => (int) ($plan['ip_limit'] ?? 1),
            'servers' => $servers,
            'extras' => $extras,
            'extras_kept' => $extras,
        ];
    }

    /**
     * The draft as the panel's request sends it: the optional prices and checkout note null while blank, no category
     * as "", the plan's own extras list or null for the shop's.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function request(array $draft): array
    {
        $note = (string) ($draft['checkout_note'] ?? '');

        return [
            'name' => (string) ($draft['name'] ?? ''),
            'emoji_id' => $draft['emoji_id'] ?? null,
            'category_id' => (int) ($draft['category_id'] ?? 0) > 0 ? (string) $draft['category_id'] : '',
            'description' => (string) ($draft['description'] ?? ''),
            'price' => (string) ($draft['price'] ?? ''),
            'first_purchase_price' => $draft['first_purchase_price'] ?? null,
            'agent_price' => $draft['agent_price'] ?? null,
            'checkout_note' => trim($note) === '' ? null : $note,
            'button_style' => $draft['button_style'] ?? null,
            'button_row' => (int) ($draft['button_row'] ?? 1),
            'badge' => $draft['badge'] ?? null,
            'duration_hours' => (string) ($draft['duration_hours'] ?? ''),
            'traffic_gb' => (string) ($draft['traffic_gb'] ?? ''),
            'ip_limit' => (string) ($draft['ip_limit'] ?? ''),
            'servers' => array_values(Json::part($draft, 'servers')),
            'is_active' => (bool) ($draft['is_active'] ?? true),
            'extras' => is_array($draft['extras'] ?? null) ? $draft['extras'] : null,
        ];
    }

    /** The tab a refused field points at (by its head: `extras.traffic_price` is the extras'). */
    public static function tab(string $field): string
    {
        return self::TAB_OF[explode('.', $field)[0]] ?? 'servers';
    }

    /**
     * A plan's own extras price list as the panel answers it, as its request sends it: amounts as typed Toman ("" for
     * none), each amount offered with its own price or "" for the unit price.
     *
     * @param array<string, mixed> $extras
     * @return array<string, mixed>
     */
    public static function extrasOf(array $extras): array
    {
        $list = [];
        foreach (['traffic', 'time', 'devices'] as $kind) {
            $list["{$kind}_price"] = CatalogFmt::amount($extras["{$kind}_price"] ?? null) ?? '';
            $options = [];
            foreach (is_array($extras["{$kind}_options"] ?? null) ? $extras["{$kind}_options"] : [] as $option) {
                if (is_array($option)) {
                    $options[] = ['quantity' => (string) ($option['quantity'] ?? ''), 'price' => CatalogFmt::amount($option['price'] ?? null) ?? ''];
                }
            }
            $list["{$kind}_options"] = $options;
            $list["{$kind}_min"] = (string) ($extras["{$kind}_min"] ?? '1');
            $list["{$kind}_max"] = (string) ($extras["{$kind}_max"] ?? '1');
        }

        return $list;
    }

    /** A decimal as typed: "30", "0.5" — no trailing zeros. */
    private static function decimal(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '0';

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
