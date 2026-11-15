<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Marketing\Discounts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Sections\Marketing\Words;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🏷 کدهای تخفیف» (the panel's «کدهای تخفیف»): every code, newest first, searched by its text or note — each opening
 * its own screen (DiscountCodeScreen) —, «➕ ساخت کد تخفیف» (DiscountCodeForm), the section's settings
 * (DiscountSettingsScreen) and the panel's ⓘ on how discounts stack. The panel has no route for one code: the page's
 * rows are kept for its buttons, and a code is read afresh (fresh()) for its screen and its edit.
 *
 * Actions: `info`.
 */
final class DiscountCodesScreen extends ListScreen
{
    public const ID = 'dc';

    /** How the shop's discounts stack: the panel's ⓘ on the page. */
    private const PROGRAM = 'قیمت هر خرید، تمدید، حجم، زمان یا کاربر اضافه اول با بیشترین تخفیف خودکار مشتری کم می‌شود — فروش ویژه، تخفیف اختصاصی او یا تخفیف سطح وفاداری‌اش؛ هیچ‌وقت دو تا با هم جمع نمی‌شوند — و بعد کد تخفیفی که مشتری وارد کرده روی همان مبلغ اعمال می‌شود. هر سفارش پرداخت‌نشده‌ای که با کد ثبت شود یکی از دفعات استفاده کد را نگه می‌دارد؛ اگر لغو شود یا مهلتش تمام شود، آن سهم آزاد می‌شود. شارژ کیف پول و خرید حجم نمایندگی تخفیف نمی‌گیرند.';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '🏷 کدهای تخفیف';
    }

    protected function path(): array
    {
        return Words::PATH;
    }

    protected function endpoint(): string
    {
        return '/discount-codes';
    }

    protected function key(): string
    {
        return 'codes';
    }

    protected function searchHint(): string
    {
        return 'کد یا یادداشت آن';
    }

    protected function empty(): string
    {
        return 'کدی پیدا نشد. با «➕ ساخت کد تخفیف» کدی بسازید — درصدی، مبلغ ثابت یا رایگان — و آن را به مشتری‌ها بدهید؛ در ربات و وب‌سایت، هنگام پرداخت وارد می‌شود.';
    }

    protected function summary(array $meta): array
    {
        return ['کدهایی که مشتری هنگام پرداخت در ربات یا وب‌سایت وارد می‌کند.'];
    }

    protected function keepsRows(): bool
    {
        return true;
    }

    protected function parent(): string
    {
        return 'hm';
    }

    protected function item(array $row): Button
    {
        $worth = ($row['type'] ?? null) === 'percent' ? Fmt::percent((int) ($row['value'] ?? 0)) : self::worth($row);

        return Button::to(Words::mark($row['state'] ?? null) . ' ' . Words::iso((string) ($row['code'] ?? '')) . ' · ' . $worth . ' · ' . self::uses($row) . ' بار', DiscountCodeScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        $view->row(Button::to('➕ ساخت کد تخفیف', DiscountCodeForm::ID, 'new'))
            ->row(Button::to('⚙️ تنظیمات تخفیف‌ها', DiscountSettingsScreen::ID), Button::to('❔ راهنما', self::ID, 'info'));
    }

    protected function action(Admin $admin, string $action, array $args): View
    {
        if ($action === 'info') {
            return Words::help(self::ID, 'کدهای تخفیف', self::PROGRAM);
        }

        return $this->show($admin, []);
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
     * A code from the list's kept page; null when it is not there.
     *
     * @return array<string, mixed>|null
     */
    public static function kept(Admin $admin, int $id): ?array
    {
        $rows = (array) ($admin->state(self::ID)['rows'] ?? []);
        $row = $rows[$id] ?? $rows[(string) $id] ?? null;

        return is_array($row) ? $row : null;
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
        $text = self::kept($admin, $id)['code'] ?? null;
        $row = is_string($text) && $text !== '' ? self::find($admin, $id, ['search' => $text]) : null;
        $row ??= self::find($admin, $id, []);

        return $row === null ? null : self::keep($admin, $row);
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
            foreach ((array) ($answer['codes'] ?? []) as $row) {
                $at = is_array($row) ? (int) ($row['id'] ?? 0) : 0;
                if ($at === $id && is_array($row)) {
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
}
