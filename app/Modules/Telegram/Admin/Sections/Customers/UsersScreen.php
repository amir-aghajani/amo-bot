<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\ListScreen;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «👥 کاربران» (the panel's «کاربران»): every customer of the bot or the website, newest first — searched by name,
 * handle, email, phone or number, narrowed by the panel's status and role pills (chips here), by one of the admin's
 * groups and ordered by a column as the panel's headers order it (UsersFilterScreen). Each row is a button — their mark,
 * name and the fact the list is ordered by (their wallet at first) — opening the customer's page (CustomerScreen). The
 * group and the order are this list's state beside its chips (`group`, `sort`, `dir`), said in the line of how many;
 * their buttons, beside the search, say only what they pick.
 */
final class UsersScreen extends ListScreen
{
    public const ID = 'us';

    public const TITLE = Icons::CUSTOMERS . ' کاربران';

    /** The panel's sorts, its default first: each order's two directions, as the screen's button names them. */
    public const SORTS = [
        'joined' => ['desc' => 'تازه‌ترین عضو اول', 'asc' => 'قدیمی‌ترین عضو اول'],
        'last_seen' => ['desc' => 'تازه‌ترین بازدید اول', 'asc' => 'قدیمی‌ترین بازدید اول'],
        'balance' => ['desc' => 'بیشترین موجودی اول', 'asc' => 'کمترین موجودی اول'],
        'orders' => ['desc' => 'بیشترین سفارش اول', 'asc' => 'کمترین سفارش اول'],
        'services' => ['desc' => 'بیشترین سرویس فعال اول', 'asc' => 'کمترین سرویس فعال اول'],
    ];

    /** What a group deleted while picked is called. */
    public const GONE_GROUP = 'گروه حذف‌شده';

    /**
     * The state of the list being drawn — its group and order —, for the query, the rows and the words over them.
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
        return self::TITLE;
    }

    protected function path(): array
    {
        return [Words::ROOT, Sales::TITLE];
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

    protected function noun(): string
    {
        return 'کاربر';
    }

    protected function filters(): array
    {
        return [
            'status' => [self::ANY => 'همه وضعیت‌ها', 'active' => Words::ACTIVE, 'banned' => Customer::BANNED],
            'role' => [self::ANY => 'همه نقش‌ها', 'admin' => Icons::BOT_ADMINS . ' مدیران ربات', 'customer' => Icons::CUSTOMER . ' مشتری‌ها'],
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

    /** The group and the order in force, when they are not the list's own, in the line of how many (plain text). */
    protected function narrowedBy(): array
    {
        $said = [];
        if (is_int($this->state['group'] ?? null)) {
            $said[] = 'گروه: ' . (is_string($this->state['group_name'] ?? null) ? $this->state['group_name'] : self::GONE_GROUP);
        }
        [$sort, $dir] = self::order($this->state);
        if ([$sort, $dir] !== ['joined', 'desc']) {
            $said[] = 'ترتیب: ' . self::SORTS[$sort][$dir];
        }

        return $said;
    }

    protected function item(array $row): Button
    {
        return Button::to(Customer::label($row, self::order($this->state)[0]), CustomerScreen::ID, Nav::SHOW, (int) ($row['id'] ?? 0));
    }

    /** Beside «🔎 جستجو»: the group and the order pickers, saying only what they pick. */
    protected function tools(Admin $admin): array
    {
        return [
            Button::to(Icons::GROUPS . ' گروه', UsersFilterScreen::ID, Nav::SHOW, UsersFilterScreen::GROUP),
            Button::to('↕️ ترتیب', UsersFilterScreen::ID, Nav::SHOW, UsersFilterScreen::SORT),
        ];
    }
}
