<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * How the «تخفیف و جوایز» screens say things — the panel's own words for order kinds, states and prizes —, the shop's
 * rows their forms pick from (plans, servers, customer groups, payment methods) and the lists the panel answers whole,
 * read from the panel's API.
 */
final class Words
{
    /** Where every screen of the section is. */
    public const PATH = ['مدیریت', 'تخفیف و جوایز'];

    /** The orders a customer places for themselves, as the panel words them (OWN_ORDER_TYPE), in its order. */
    public const KINDS = ['purchase' => 'خرید', 'renewal' => 'تمدید', 'extra_traffic' => 'حجم اضافه', 'extra_time' => 'زمان اضافه', 'extra_devices' => 'کاربر اضافه'];

    /** What a discount takes for: every kind of order (the panel's «همه»). */
    public const EVERY_KIND = 'همه: خرید، تمدید، حجم، زمان و کاربر اضافه';

    /** What a prize gives (WHEEL_PRIZE_KIND), in the order its form offers them. */
    public const PRIZES = ['wallet' => 'شارژ کیف پول', 'traffic' => 'حجم', 'days' => 'زمان', 'plan' => 'سرویس رایگان', 'nothing' => 'پوچ'];

    /** How a discount code or a sale stands now (DISCOUNT_STATE). */
    private const STATES = ['active' => '🟢 فعال', 'scheduled' => '🕒 شروع نشده', 'ended' => '⚪️ تمام‌شده', 'used_up' => '🟠 ظرفیت پر', 'off' => '⏸ غیرفعال'];

    /** A row switched on or off (a cashback rule, a wheel slice), in the management's words for records. */
    public static function onOff(bool $on): string
    {
        return $on ? '🟢 فعال' : '⏸ غیرفعال';
    }

    /**
     * A list's «❔ راهنما»: the panel's ⓘ beside the page's title, under the program it explains, back to its list.
     *
     * @param string $program The program's name (plain text, no emoji): «فروش ویژه»
     * @param string $text What the panel's ⓘ says (plain text)
     */
    public static function help(string $screen, string $program, string $text): View
    {
        return View::make('❔ ' . $program, ...[...self::PATH, $program])->line(Fmt::e($text))->back($screen);
    }

    /**
     * Every row of a list the panel answers whole (its sales, tiers, rules, wheel slices — no pages of its own).
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function all(Admin $admin, string $endpoint, string $key): array
    {
        return array_values(array_filter((array) ($admin->api->get($endpoint)[$key] ?? []), is_array(...)));
    }

    /**
     * One row of such a list as the panel has it now, by id; null for none (deleted since).
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    public static function one(Admin $admin, string $endpoint, string $key, int $id): ?array
    {
        foreach (self::all($admin, $endpoint, $key) as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    }

    /** Latin text in a button's label, isolated so a Persian label keeps its order (no HTML there). */
    public static function iso(string $text): string
    {
        return "\u{2068}" . $text . "\u{2069}";
    }

    /** The kinds of order a discount names: «خرید، تمدید», or «همه سفارش‌ها» for none named. */
    public static function kinds(mixed $kinds): string
    {
        if (!is_array($kinds)) {
            return 'همه سفارش‌ها';
        }

        return implode('، ', array_map(static fn(mixed $kind): string => self::KINDS[is_string($kind) ? $kind : ''] ?? (is_string($kind) ? $kind : ''), $kinds));
    }

    /** A few rows a discount names, in words: «پلن «یک‌ماهه»» for one, «۳ پلن» for more. */
    public static function named(string $noun, mixed $rows): string
    {
        $rows = is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];

        return count($rows) === 1 ? $noun . ' «' . (string) ($rows[0]['name'] ?? '') . '»' : Fmt::number(count($rows)) . ' ' . $noun;
    }

    /** A discount's window in words: «همیشه», «تا …», «از …», «… تا …». */
    public static function window(mixed $starts, mixed $ends): string
    {
        $starts = is_string($starts) ? $starts : null;
        $ends = is_string($ends) ? $ends : null;

        return match (true) {
            $starts === null && $ends === null => 'همیشه',
            $starts === null => 'تا ' . Fmt::date($ends, true),
            $ends === null => 'از ' . Fmt::date($starts, true),
            default => Fmt::date($starts, true) . ' تا ' . Fmt::date($ends, true),
        };
    }

    /** A code's or a sale's state, with its mark. */
    public static function state(mixed $state): string
    {
        return self::STATES[is_string($state) ? $state : ''] ?? (is_string($state) ? $state : Fmt::NONE);
    }

    /** Its mark alone, for a list's row. */
    public static function mark(mixed $state): string
    {
        return explode(' ', self::state($state))[0];
    }

    /**
     * What a wheel slice gives, in words (the panel's prizeWorth): «۱۰ گیگابایت حجم», «سرویس «یک‌ماهه»».
     *
     * @param array<string, mixed> $prize
     */
    public static function prize(array $prize): string
    {
        $amount = is_numeric($prize['amount'] ?? null) ? (string) $prize['amount'] : '0';
        $plan = is_array($prize['plan'] ?? null) ? $prize['plan'] : null;

        return match ($prize['kind'] ?? null) {
            'wallet' => Fmt::money($amount) . ' شارژ کیف پول',
            'traffic' => Fmt::digits(str_replace('.', '٫', self::trim($amount))) . ' گیگابایت حجم',
            'days' => Fmt::number($amount) . ' روز زمان',
            'plan' => $plan !== null ? 'سرویس «' . (string) ($plan['name'] ?? '') . '»' : 'سرویس رایگان — پلنش حذف شده',
            default => self::PRIZES['nothing'],
        };
    }

    /** A decimal without trailing zeros: "2.50" → "2.5", "7.00" → "7". */
    public static function trim(string $decimal): string
    {
        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /** A customer as a list names them (UserDirectory::presentRef()): «علی · @ali»; a deleted account said so. */
    public static function customer(mixed $ref): string
    {
        if (!is_array($ref)) {
            return 'حساب حذف‌شده';
        }
        $name = is_string($ref['name'] ?? null) && $ref['name'] !== '' ? $ref['name'] : 'بی‌نام';

        return $name . (is_string($ref['username'] ?? null) && $ref['username'] !== '' ? ' · @' . $ref['username'] : '');
    }

    /**
     * The shop's plans by id, a plan off sale said so (a prize or a discount may name one).
     *
     * @return array<int, string>
     * @throws ApiRefusal
     */
    public static function plans(Admin $admin, string $off = 'غیرفعال'): array
    {
        return self::rows($admin->api->get('/plans')['plans'] ?? [], 'name', $off);
    }

    /**
     * The servers a discount may name (the plan form's), one off said so.
     *
     * @return array<int, string>
     * @throws ApiRefusal
     */
    public static function servers(Admin $admin): array
    {
        return self::rows($admin->api->get('/plans/options')['servers'] ?? [], 'name', 'غیرفعال');
    }

    /**
     * The customer groups by id.
     *
     * @return array<int, string>
     * @throws ApiRefusal
     */
    public static function groups(Admin $admin): array
    {
        return self::rows($admin->api->get('/customer-groups')['groups'] ?? [], 'name', '');
    }

    /**
     * The shop's payment methods by id.
     *
     * @return array<int, string>
     * @throws ApiRefusal
     */
    public static function methods(Admin $admin): array
    {
        return self::rows($admin->api->get('/payment-methods')['methods'] ?? [], 'label', '');
    }

    /**
     * Rows by id, each by `$name`, one switched off marked with `$off`.
     *
     * @return array<int, string>
     */
    private static function rows(mixed $rows, string $name, string $off): array
    {
        $named = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_int($row['id'] ?? null)) {
                $named[$row['id']] = (string) ($row[$name] ?? '') . ($off !== '' && ($row['is_active'] ?? true) === false ? ' (' . $off . ')' : '');
            }
        }

        return $named;
    }
}
