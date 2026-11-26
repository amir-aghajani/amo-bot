<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as Ui;

/**
 * How the «تخفیف و جوایز» screens say things — the panel's own words for order kinds, states and prizes, each program's
 * name and icon, a row's delete asked first —, the shop's rows their forms pick from (plans, servers, customer groups,
 * payment methods) and the lists the panel answers whole, read from the panel's API. Screens
 * import it as `Marketing` beside the management's own Words.
 */
final class Words
{
    /** The menu every program's list goes back to (Screens' hub). */
    public const MENU = 'hm';

    /** Where every screen of the section is: the management, then the menu. */
    public const PATH = [Ui::ROOT, 'تخفیف و جوایز'];

    // The programs, as the menu names them (Screens' hub MENU) — a screen's title is `Icons::X . ' ' . name`.
    public const DISCOUNTS = 'کدهای تخفیف';
    public const SALES = 'فروش ویژه';
    public const LOYALTY = 'سطوح وفاداری';
    public const CASHBACK = 'کش‌بک';
    public const GIFT_CODES = 'کدهای هدیه';
    public const WHEEL = 'گردونه شانس';
    public const LOTTERY = 'قرعه‌کشی';

    /** The orders a customer places for themselves, as the panel words them (OWN_ORDER_TYPE), in its order. */
    public const KINDS = ['purchase' => 'خرید', 'renewal' => 'تمدید', 'extra_traffic' => 'حجم اضافه', 'extra_time' => 'زمان اضافه', 'extra_devices' => 'کاربر اضافه'];

    /** What a discount takes for: every kind of order (the panel's «همه»). */
    public const EVERY_KIND = 'همه: خرید، تمدید، حجم، زمان و کاربر اضافه';

    /** What a prize gives (WHEEL_PRIZE_KIND), in the order its form offers them. */
    public const PRIZES = ['wallet' => 'شارژ کیف پول', 'traffic' => 'حجم', 'days' => 'زمان', 'plan' => 'سرویس رایگان', 'nothing' => 'پوچ'];

    /** How a discount code or a sale stands now (DISCOUNT_STATE), in the order a list's chips offer them. */
    public const STATES = ['active' => Ui::ACTIVE, 'scheduled' => '🕒 شروع نشده', 'ended' => '⚪️ تمام‌شده', 'used_up' => '🟠 ظرفیت پر', 'off' => Ui::INACTIVE];

    // A wizard's own words (Form\FormScreen).

    /** A step left as it is on the way through: a value it has kept. */
    public const KEEP = '⏭ همین بماند';

    /** Several ticked: the step done. */
    public const TICKED = '✔️ تایید';

    /** A code's or a sale's «پلن‌ها» done with none ticked (Step::refusesNone()): every plan is a button of its own. */
    public const REACH_NONE = 'دست‌کم یک دسته یا پلن انتخاب کنید، یا «همه پلن‌ها».';

    /** The way through over: every field once more before the save. */
    public const REVIEW = 'همه را یک بار ببینید؛ هر کدام را می‌شود عوض کرد، بعد بسازید.';

    /** A row's form opened to edit, nothing changed yet. */
    public const UNCHANGED = 'هنوز چیزی را عوض نکرده‌اید.';

    /** A row's form with changes the save has not sent. */
    public const UNSAVED = '✏️ تغییرها هنوز ذخیره نشده‌اند.';

    /**
     * The path of a program's own screens: the section, then the program.
     *
     * @return list<string>
     */
    public static function in(string $program): array
    {
        return [...self::PATH, $program];
    }

    /** The settings card of a program: «⚙️ تنظیمات کش‌بک». */
    public static function settings(string $of): string
    {
        return Icons::SETTINGS . ' تنظیمات ' . $of;
    }

    /**
     * Every row of a list the panel answers whole (its sales, tiers, rules, wheel slices — no pages of its own).
     *
     * @return list<array<string, mixed>>
     * @throws ApiRefusal
     */
    public static function all(Admin $admin, string $endpoint, string $key): array
    {
        return Json::rows($admin->api->get($endpoint), $key);
    }

    /**
     * One row of such a list as the panel has it now, by id; null for none (deleted since).
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    public static function one(Admin $admin, string $endpoint, string $key, int $id): ?array
    {
        return self::find(self::all($admin, $endpoint, $key), $id);
    }

    /**
     * Row `$id` among `$rows`; null when it is not there.
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
     * The ids of the rows a discount or a sale names (its plans, its servers); null for every one.
     *
     * @return list<int>|null
     */
    public static function ids(mixed $rows): ?array
    {
        return is_array($rows) ? array_values(array_map(static fn(mixed $row): int => is_array($row) ? (int) ($row['id'] ?? 0) : 0, $rows)) : null;
    }

    /**
     * A row's «🗑 حذف», asked first: `$question`, what it does (`$detail`, HTML), «🗑 بله، حذف شود» to `$screen`'s `gone`
     * and «✖️ انصراف» back to the row's own page — both with its id.
     */
    public static function deletion(Admin $admin, string $screen, int $id, string $question, string $detail): View
    {
        return $admin->confirm($question, Button::to(Ui::yes('حذف شود', '🗑'), $screen, 'gone', $id), Button::to(Ui::CANCEL, $screen, 'show', $id), $detail, danger: true);
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

    /** A code's or a sale's state, as its chip. */
    public static function state(mixed $state): string
    {
        return self::STATES[is_string($state) ? $state : ''] ?? (is_string($state) ? $state : Fmt::NONE);
    }

    /** Its chip's mark alone, for a list's row. */
    public static function mark(mixed $state): string
    {
        return explode(' ', self::state($state))[0];
    }

    /** A row switched on or off (a rule, a slice) as its chip's mark, for a list's row: «🟢», «⏸». */
    public static function dot(bool $active): string
    {
        return explode(' ', Fmt::active($active))[0];
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

    /**
     * A customer as a list names them (UserDirectory::presentRef()): «علی · @ali», the handle isolated (iso()) so it keeps
     * its order in a Persian line or label; a deleted account said so.
     */
    public static function customer(mixed $ref): string
    {
        if (!is_array($ref)) {
            return 'حساب حذف‌شده';
        }
        $name = is_string($ref['name'] ?? null) && $ref['name'] !== '' ? $ref['name'] : 'بی‌نام';

        return $name . (is_string($ref['username'] ?? null) && $ref['username'] !== '' ? ' · ' . self::iso('@' . $ref['username']) : '');
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
     * A code's or a sale's plans as one list to tick (the panel's PlanReachField): the shop's categories first, «🗂 ماهانه»
     * — each standing for every plan filed under it, one moved there later too —, then its plans; keyed `c<id>` and
     * `p<id>` (reachOf(), reachBody()).
     *
     * @return array<string, string>
     * @throws ApiRefusal
     */
    public static function planReach(Admin $admin): array
    {
        $reach = [];
        foreach (self::rows($admin->api->get('/plans/options')['categories'] ?? [], 'name', 'غیرفعال') as $id => $name) {
            $reach['c' . $id] = Icons::CATEGORIES . ' ' . $name;
        }
        foreach (self::plans($admin) as $id => $name) {
            $reach['p' . $id] = $name;
        }

        return $reach;
    }

    /**
     * A code's or a sale's `plans` and `categories` as the one list planReach() ticks; null for every plan (both null).
     * Either list empty — all it named deleted since — stays an empty list: it holds for no plan.
     *
     * @return list<string>|null
     */
    public static function reachOf(mixed $plans, mixed $categories): ?array
    {
        if (!is_array($plans) && !is_array($categories)) {
            return null;
        }

        return [
            ...array_map(static fn(int $id): string => 'c' . $id, self::ids($categories) ?? []),
            ...array_map(static fn(int $id): string => 'p' . $id, self::ids($plans) ?? []),
        ];
    }

    /**
     * The list planReach() ticked as the API takes it: `plan_ids` and `category_ids`, both null for every plan.
     *
     * @return array{plan_ids: list<int>|null, category_ids: list<int>|null}
     */
    public static function reachBody(mixed $reach): array
    {
        if (!is_array($reach)) {
            return ['plan_ids' => null, 'category_ids' => null];
        }
        $ids = static fn(string $kind): array => array_values(array_map(
            static fn(string $key): int => (int) substr($key, 1),
            array_filter($reach, static fn(mixed $key): bool => is_string($key) && str_starts_with($key, $kind)),
        ));

        return ['plan_ids' => $ids('p'), 'category_ids' => $ids('c')];
    }

    /**
     * A code's or a sale's draft as 0.11.0 keeps it, from one an older version left in the chat (its plans under
     * `plan_ids`, no `plans`): those plans ticked in the one list (`p<id>`) — none for none, every plan only for every
     * plan (null), never wider —; any other draft as it is.
     *
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public static function reachDraft(array $draft): array
    {
        if (array_key_exists('plans', $draft) || !array_key_exists('plan_ids', $draft)) {
            return $draft;
        }
        $plans = $draft['plan_ids'];
        unset($draft['plan_ids']);
        $draft['plans'] = is_array($plans) ? array_values(array_map(static fn(mixed $id): string => 'p' . (is_numeric($id) ? (int) $id : 0), $plans)) : null;

        return $draft;
    }

    /**
     * The note under a code's or a sale's review while nothing is ticked in «پلن‌ها» — every category and plan it named
     * deleted since —: its save is refused, as the panel's form refuses it; none otherwise.
     *
     * @return list<string>
     */
    public static function reachNotes(mixed $reach): array
    {
        return $reach === [] ? ['در «پلن‌ها» چیزی انتخاب نشده؛ دسته یا پلنی انتخاب کنید، یا «همه پلن‌ها».'] : [];
    }

    /**
     * A code's or a sale's plans in words (the panel's planReachWords): «دسته «ماهانه»», «۲ دسته و پلن «ویژه»»; «هیچ پلنی»
     * once all it named is gone; null for every plan.
     */
    public static function reach(mixed $plans, mixed $categories): ?string
    {
        if (!is_array($plans) && !is_array($categories)) {
            return null;
        }
        $parts = [];
        if (is_array($categories) && $categories !== []) {
            $parts[] = self::named('دسته', $categories);
        }
        if (is_array($plans) && $plans !== []) {
            $parts[] = self::named('پلن', $plans);
        }

        return $parts === [] ? 'هیچ پلنی' : implode(' و ', $parts);
    }

    /**
     * The plans a gift may give (a gift code, a wheel slice, the draw's prize): a plan off sale too, said to be for gifts.
     *
     * @return array<int, string>
     * @throws ApiRefusal
     */
    public static function giftPlans(Admin $admin): array
    {
        return self::plans($admin, 'غیرفعال؛ فقط برای هدیه');
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
