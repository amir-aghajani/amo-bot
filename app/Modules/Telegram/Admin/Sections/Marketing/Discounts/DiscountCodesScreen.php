<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Words as Marketing;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🏷 کدهای تخفیف» (the panel's «کدهای تخفیف»): every code, newest first — each a button with its state's chip, its text,
 * what it takes off and its uses, opening its own screen (DiscountCodeScreen) —, narrowed by its state (chips: active,
 * not started, ended, used up, off) and searched by its text or note; «➕ افزودن کد تخفیف» (DiscountCodeForm), the
 * section's settings (DiscountSettingsScreen) and the panel's ⓘ on how discounts stack, folded. The panel's list has no
 * state filter (a code's state is worked out as it is shown): narrowed, the list is read whole — its pages followed, up
 * to WHOLE_MOST of them, any older codes said to be left out — and cut here. The panel has no route for one code either:
 * the page's rows are kept for its buttons, and a code is read afresh (fresh()) for its screen and its edit.
 *
 * Actions: ListScreen's; `info` (an older window's «❔ راهنما»: the list, its help folded in it).
 */
final class DiscountCodesScreen extends ListScreen
{
    public const ID = 'dc';

    public const TITLE = Icons::DISCOUNTS . ' ' . Marketing::DISCOUNTS;

    /** How the shop's discounts stack: the panel's ⓘ on the page. */
    private const PROGRAM = 'کدهایی که مشتری هنگام پرداخت در ربات یا وب‌سایت وارد می‌کند. قیمت هر خرید، تمدید، حجم، زمان یا کاربر اضافه اول با بیشترین تخفیف خودکار مشتری کم می‌شود — فروش ویژه، تخفیف اختصاصی او یا تخفیف سطح وفاداری‌اش؛ هیچ‌وقت دو تا با هم جمع نمی‌شوند — و بعد کد تخفیفی که مشتری وارد کرده روی همان مبلغ اعمال می‌شود. هر سفارش پرداخت‌نشده‌ای که با کد ثبت شود یکی از دفعات استفاده کد را نگه می‌دارد؛ اگر لغو شود یا مهلتش تمام شود، آن سهم آزاد می‌شود. شارژ کیف پول و خرید حجم نمایندگی تخفیف نمی‌گیرند.';

    /** The most of the panel's pages read for a list narrowed to a state (25 codes each). */
    private const WHOLE_MOST = 40;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    /** Narrowed to a state, the list read whole and cut here; else the panel's pages, as any list. */
    public function show(Admin $admin, array $args): View
    {
        $this->here = $admin;
        $state = $admin->state(self::ID);
        $chip = (string) (Json::part($state, 'f')['state'] ?? self::ANY);
        if ($chip === self::ANY || !isset(Marketing::STATES[$chip])) {
            return parent::show($admin, $args);
        }

        $search = (string) ($state['search'] ?? '');
        [$every, $capped] = self::every($admin, $search);
        $rows = array_values(array_filter($every, static fn(array $row): bool => ($row['state'] ?? null) === $chip));
        [$shown, $page, $pages] = View::slice($rows, (int) ($state['page'] ?? 1));
        $admin->remember(self::ID, ['page' => $page, 'rows' => array_column($shown, null, 'id')]);

        // As any list lays it out (ListScreen::show()): how many and what narrows them, the rows, what more it offers, the
        // chips, the search, the pages.
        $view = View::make($this->title(), ...$this->path())
            ->total(count($rows), $this->noun(), Marketing::STATES[$chip], $search === '' ? '' : '🔎 «' . $search . '»');
        if ($capped) {
            // Past WHOLE_MOST pages the oldest codes are not read: the count is of the newest ones, said so.
            $view->line('<i>فقط ' . Fmt::number(count($every)) . ' کد تازه‌تر بررسی شد؛ قدیمی‌ترها را با جستجو پیدا کنید.</i>');
        }
        if ($rows === []) {
            $view->line()->line(Fmt::e(Words::NOTHING_FOUND));
        }
        foreach ($shown as $row) {
            $view->row($this->item($row));
        }
        $this->extra($view, $admin);
        $chips = [];
        foreach ($this->filters()['state'] as $value => $label) {
            $chips[] = Button::to(($chip === (string) $value ? Words::CHIP : '') . $label, self::ID, 'f', 'state', (string) $value);
        }

        return $view->grid(3, ...$chips)
            ->row($search === '' ? Button::to(Words::SEARCH, self::ID, 'search') : Button::to(Words::CLEAR_SEARCH, self::ID, 'clear'))
            ->pager($page, $pages, self::ID, 'page')
            ->back($this->parent())->refresh(self::ID);
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function path(): array
    {
        return Marketing::PATH;
    }

    protected function endpoint(): string
    {
        return '/discount-codes';
    }

    protected function key(): string
    {
        return 'codes';
    }

    protected function filters(): array
    {
        return ['state' => [self::ANY => 'همه', ...Marketing::STATES]];
    }

    protected function searchHint(): string
    {
        return 'کد یا یادداشت آن';
    }

    protected function noun(): string
    {
        return 'کد';
    }

    protected function empty(): string
    {
        return ($this->here?->state(self::ID)['search'] ?? '') !== ''
            ? Words::NOTHING_FOUND
            : 'هنوز کد تخفیفی نساخته‌اید. کدی بسازید — درصدی، مبلغ ثابت یا رایگان — و به مشتری‌ها بدهید؛ در ربات و وب‌سایت هنگام پرداخت وارد می‌شود.';
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return Marketing::MENU;
    }

    protected function item(array $row): Button
    {
        $worth = ($row['type'] ?? null) === 'percent' ? Fmt::percent((int) ($row['value'] ?? 0)) : self::worth($row);

        return Button::to(Marketing::mark($row['state'] ?? null) . ' ' . Marketing::iso((string) ($row['code'] ?? '')) . ' · ' . $worth . ' · ' . self::uses($row) . ' بار', DiscountCodeScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to(Words::add('کد تخفیف'), DiscountCodeForm::ID, 'new')->success(), Button::to(Words::SETTINGS, DiscountSettingsScreen::ID))
            ->help(Fmt::e(self::PROGRAM));
    }

    /**
     * What a code takes off, in a few words (the panel's codeWorth): «۲۰٪ تا ۵۰٬۰۰۰ تومان», «۱۵٬۰۰۰ تومان», «رایگان».
     *
     * @param array<string, mixed> $code
     */
    public static function worth(array $code): string
    {
        $value = is_numeric($code['value'] ?? null) ? (string) $code['value'] : '0';

        return match ($code['type'] ?? null) {
            'percent' => Fmt::percent((int) $value) . (is_numeric($code['max_discount'] ?? null) ? ' تا ' . Fmt::money((string) $code['max_discount']) : ''),
            'fixed' => Fmt::money($value),
            default => 'رایگان',
        };
    }

    /**
     * How often a code was used: «۳», or «۳ از ۱۰» against its cap.
     *
     * @param array<string, mixed> $code
     */
    public static function uses(array $code): string
    {
        $uses = Fmt::number((int) ($code['uses'] ?? 0));

        return is_int($code['max_uses'] ?? null) ? $uses . ' از ' . Fmt::number($code['max_uses']) : $uses;
    }

    /**
     * A code as the panel has it now, kept for the list's page too; null once it is gone. The panel has no route for one
     * code: it is looked up by its kept text in the list's search, and failing that (renamed since, or never kept) down
     * the whole list, newest first, until past its id.
     *
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    public static function fresh(Admin $admin, int $id): ?array
    {
        $text = self::keptRow($admin, self::ID, $id)['code'] ?? null;
        $row = is_string($text) && $text !== '' ? self::find($admin, $id, ['search' => $text]) : null;
        $row ??= self::find($admin, $id, []);

        return $row === null ? null : self::keep($admin, $row);
    }

    /**
     * A code as the panel answered it, kept for the list's page too.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function keep(Admin $admin, array $row): array
    {
        $rows = (array) ($admin->state(self::ID)['rows'] ?? []);
        $rows[(int) ($row['id'] ?? 0)] = $row;
        $admin->remember(self::ID, ['rows' => $rows]);

        return $row;
    }

    /**
     * Every code the search finds (every code, for none), newest first: the panel's pages followed, WHOLE_MOST at most —
     * and whether there were more than those (the oldest left unread).
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     * @throws ApiRefusal
     */
    private static function every(Admin $admin, string $search): array
    {
        $query = $search === '' ? [] : ['search' => $search];
        $rows = [];
        for ($page = 1; $page <= self::WHOLE_MOST; $page++) {
            $answer = $admin->api->get('/discount-codes', $query + ['page' => $page]);
            $rows = [...$rows, ...Json::rows($answer, 'codes')];
            if (!is_array($answer['meta'] ?? null) || $page >= (int) ($answer['meta']['last_page'] ?? 1)) {
                return [$rows, false];
            }
        }

        return [$rows, true];
    }

    /**
     * Code `$id` among the list's rows for `$query`, page by page; null when it is not there.
     *
     * @param array<string, string> $query
     * @return array<string, mixed>|null
     * @throws ApiRefusal
     */
    private static function find(Admin $admin, int $id, array $query): ?array
    {
        for ($page = 1; ; $page++) {
            $answer = $admin->api->get('/discount-codes', $query + ['page' => $page]);
            foreach (Json::rows($answer, 'codes') as $row) {
                $at = (int) ($row['id'] ?? 0);
                if ($at === $id) {
                    return $row;
                }
                if ($at > 0 && $at < $id) {
                    // Newest first: past its id, it is not on the list.
                    return null;
                }
            }
            if (!is_array($answer['meta'] ?? null) || $page >= (int) ($answer['meta']['last_page'] ?? 1)) {
                return null;
            }
        }
    }
}
