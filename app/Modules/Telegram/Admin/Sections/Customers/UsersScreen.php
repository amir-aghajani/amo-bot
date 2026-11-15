<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «👥 کاربران» (the panel's «کاربران»): every customer of the bot or the website, newest first — searched by name,
 * handle, email, phone or number, narrowed by the panel's status and role pills (chips here), by one of the admin's
 * groups and ordered by a column as the panel's headers order it (UsersFilterScreen). Each opens the customer's page
 * (CustomerScreen). The group and the order are this list's state beside its chips (`group`, `sort`, `dir`).
 */
final class UsersScreen extends ListScreen
{
    public const ID = 'us';

    /** The panel's sorts, its default first: each order's two directions, as the screen's button names them. */
    public const SORTS = [
        'joined' => ['desc' => 'تازه‌ترین عضو اول', 'asc' => 'قدیمی‌ترین عضو اول'],
        'last_seen' => ['desc' => 'تازه‌ترین بازدید اول', 'asc' => 'قدیمی‌ترین بازدید اول'],
        'balance' => ['desc' => 'بیشترین موجودی اول', 'asc' => 'کمترین موجودی اول'],
        'orders' => ['desc' => 'بیشترین سفارش اول', 'asc' => 'کمترین سفارش اول'],
        'services' => ['desc' => 'بیشترین سرویس فعال اول', 'asc' => 'کمترین سرویس فعال اول'],
    ];

    /**
     * The state of the list being drawn — its group and order —, for the query and the words beside the chips.
     *
     * @var array<string, mixed>
     */
    private array $state = [];

    public function show(Admin $admin, array $args): View
    {
        $this->state = $admin->state(self::ID);

        return parent::show($admin, $args);
    }

    /**
     * The order the list is in (`sort`, `dir`), the panel's default when none was picked.
     *
     * @param array<string, mixed> $state
     * @return array{0: string, 1: string}
     */
    public static function order(array $state): array
    {
        $sort = is_string($state['sort'] ?? null) && isset(self::SORTS[$state['sort']]) ? $state['sort'] : 'joined';
        $dir = ($state['dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        return [$sort, $dir];
    }

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return '👥 کاربران';
    }

    protected function path(): array
    {
        return ['مدیریت', 'فروش'];
    }

    protected function parent(): string
    {
        return 'hl';
    }

    protected function endpoint(): string
    {
        return '/users';
    }

    protected function key(): string
    {
        return 'users';
    }

    protected function filters(): array
    {
        return [
            'status' => [self::ANY => 'همه وضعیت‌ها', 'active' => 'فعال', 'banned' => 'مسدود'],
            'role' => [self::ANY => 'همه نقش‌ها', 'admin' => 'مدیران ربات', 'customer' => 'مشتری‌ها'],
        ];
    }

    protected function fixed(): array
    {
        [$sort, $dir] = self::order($this->state);
        $fixed = ['sort' => $sort, 'dir' => $dir];
        if (is_int($this->state['group'] ?? null)) {
            $fixed['group'] = $this->state['group'];
        }

        return $fixed;
    }

    protected function searchHint(): string
    {
        return 'نام، نام کاربری، ایمیل، موبایل یا شناسه؛ «#۱۲» فقط مشتری شماره ۱۲ را پیدا می‌کند.';
    }

    protected function empty(): string
    {
        $narrowed = (string) ($this->state['search'] ?? '') !== '' || is_int($this->state['group'] ?? null) || array_filter((array) ($this->state['f'] ?? []), static fn(mixed $value): bool => $value !== self::ANY) !== [];

        return $narrowed
            ? 'کاربری پیدا نشد؛ جستجو را کوتاه‌تر کنید یا فیلترها را بردارید.'
            : 'هنوز کاربری ثبت نشده است. اولین کسی که به ربات ' . Fmt::ltr('/start') . ' بزند یا در وب‌سایت ثبت‌نام کند این‌جا ظاهر می‌شود.';
    }

    protected function summary(array $meta): array
    {
        $lines = [];
        if (is_int($this->state['group'] ?? null)) {
            $lines[] = '👥 فقط گروه «' . Fmt::e(is_string($this->state['group_name'] ?? null) ? $this->state['group_name'] : 'گروه حذف‌شده') . '»';
        }
        [$sort, $dir] = self::order($this->state);
        if ([$sort, $dir] !== ['joined', 'desc']) {
            $lines[] = '↕️ ' . Fmt::e(self::SORTS[$sort][$dir]);
        }

        return $lines;
    }

    protected function item(array $row): Button
    {
        return Button::to(Customer::label($row), CustomerScreen::ID, 'show', (int) ($row['id'] ?? 0));
    }

    protected function extra(View $view, Admin $admin): void
    {
        [$sort, $dir] = self::order($this->state);
        $group = is_int($this->state['group'] ?? null) ? (is_string($this->state['group_name'] ?? null) ? $this->state['group_name'] : 'گروه حذف‌شده') : 'همه';
        $view->row(
            Button::to('👫 گروه: ' . $group, UsersFilterScreen::ID, 'show', UsersFilterScreen::GROUP),
            Button::to('↕️ ' . self::SORTS[$sort][$dir], UsersFilterScreen::ID, 'show', UsersFilterScreen::SORT),
        );
    }
}
