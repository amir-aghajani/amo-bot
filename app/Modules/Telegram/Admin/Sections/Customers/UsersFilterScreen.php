<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * The users list's two pickers beside its chips, the choice in force marked ✅: which of the admin's groups it shows (the
 * panel's «گروه» select, «همه گروه‌ها» first; a group deleted while picked still narrows it, under «گروه حذف‌شده») and
 * the order its rows are in (the panel's sortable headers, each order's two directions side by side). A pick goes back
 * to the list, on its first page. Many groups go by pages.
 *
 * Opened with GROUP (and the groups' page) or SORT. Actions: `g` (group id, 0: every group), `s` (sort, dir).
 */
final class UsersFilterScreen extends BaseScreen
{
    public const ID = 'usf';

    public const GROUP = 'g';
    public const SORT = 's';

    public function show(Admin $admin, array $args): View
    {
        return ($args[0] ?? '') === self::SORT ? $this->sorts($admin) : $this->groups($admin, self::page($args, 1));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'g':
                $id = self::id($args);
                $name = null;
                foreach (Customer::groups($admin) as $group) {
                    if (($group['id'] ?? null) === $id) {
                        $name = Customer::text($group, 'name');
                    }
                }
                $admin->remember(UsersScreen::ID, ['group' => $id > 0 ? $id : null, 'group_name' => $name, 'page' => 1]);

                return $admin->open(UsersScreen::ID);
            case 's':
                $sort = $args[0] ?? '';
                if (isset(UsersScreen::SORTS[$sort])) {
                    $admin->remember(UsersScreen::ID, ['sort' => $sort, 'dir' => ($args[1] ?? '') === 'asc' ? 'asc' : 'desc', 'page' => 1]);
                }

                return $admin->open(UsersScreen::ID);
        }

        return $this->show($admin, $args);
    }

    private function groups(Admin $admin, int $page): View
    {
        $state = $admin->state(UsersScreen::ID);
        $current = is_int($state['group'] ?? null) ? $state['group'] : 0;
        $groups = Customer::groups($admin);
        $view = View::make(Icons::GROUPS . ' کدام گروه؟', ...Customer::under())
            ->note('فقط مشتری‌های یک گروه دیده می‌شوند؛ گروه‌ها در «' . Fmt::e(GroupsScreen::TITLE) . '» ساخته می‌شوند.');
        $buttons = [Button::to(self::picked($current === 0) . 'همه گروه‌ها', self::ID, 'g', 0)];
        $found = false;
        foreach ($groups as $group) {
            $id = Customer::int($group, 'id');
            $found = $found || $id === $current;
            $buttons[] = Button::to(self::picked($id === $current) . (Customer::text($group, 'name') ?? '') . ' · ' . Fmt::plain(Fmt::number(Customer::members($group))) . ' نفر', self::ID, 'g', $id);
        }
        if ($current !== 0 && !$found) {
            $buttons[] = Button::to(Words::PICKED . UsersScreen::GONE_GROUP, self::ID, 'g', $current);
        }
        if ($groups === []) {
            $view->line('هنوز گروهی ساخته نشده است.');
        }

        return $view->paged(2, $buttons, $page, static fn(int $at): array => [self::ID, Nav::SHOW, [self::GROUP, $at]])->back(UsersScreen::ID);
    }

    private function sorts(Admin $admin): View
    {
        [$sort, $dir] = UsersScreen::order($admin->state(UsersScreen::ID));
        $view = View::make('↕️ ترتیب کاربران', ...Customer::under());
        foreach (UsersScreen::SORTS as $key => $labels) {
            $view->row(
                Button::to(self::picked($key === $sort && $dir === 'desc') . $labels['desc'], self::ID, 's', $key, 'desc'),
                Button::to(self::picked($key === $sort && $dir === 'asc') . $labels['asc'], self::ID, 's', $key, 'asc'),
            );
        }

        return $view->back(UsersScreen::ID);
    }

    /** The mark of the choice in force, or none. */
    private static function picked(bool $picked): string
    {
        return $picked ? Words::PICKED : '';
    }
}
