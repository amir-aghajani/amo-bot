<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * Which of the admin's groups a customer is in (the panel's «گروه‌ها» card and dialog): a switch per group — ✅ they are
 * in it, ▫️ not —, saved at a tap as the panel saves the dialog (PUT /users/{id}/groups, every group they are in). A
 * «همکار» group sets their prices as the plans do. Many groups go by pages, the page kept while switches are tapped; the
 * groups they are in are named over the switches then, as a page shows only some. On the seat's own account
 * (Customer::own()) a «همکار» group they are not in has no switch: their own prices are another admin's.
 *
 * Opened with the customer's id. Actions: `sw` (id, group, 1|0: in or out), `pg` (id, n: the groups' page n). State:
 * `page`, of `user`.
 */
final class CustomerGroupsScreen extends BaseScreen
{
    public const ID = 'usg';

    public const TITLE = Icons::GROUPS . ' گروه‌ها';

    public function show(Admin $admin, array $args): View
    {
        $id = Customer::current($admin, $args);
        if ($id === 0) {
            return $admin->open(UsersScreen::ID);
        }

        return $this->view($admin, Customer::read($admin, $id));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        if ($action === 'pg') {
            $admin->remember(self::ID, ['page' => self::page($args, 1), 'user' => $id]);

            return $this->show($admin, [(string) $id]);
        }
        if ($action !== 'sw' || $id === 0) {
            return $this->show($admin, $args);
        }

        $group = self::id($args, 1);
        $in = ($args[2] ?? '') === '1';
        $user = Customer::row(Customer::read($admin, $id));
        $ids = Customer::ids(Json::rows($user, 'groups'));
        $next = $in ? array_values(array_unique([...$ids, $group])) : array_values(array_diff($ids, [$group]));
        if ($next !== $ids) {
            $admin->api->put("/users/{$id}/groups", ['group_ids' => $next]);
        }
        $name = '';
        foreach (Customer::groups($admin) as $row) {
            if (Customer::int($row, 'id') === $group) {
                $name = Customer::text($row, 'name') ?? '';
            }
        }
        $admin->notice($in ? '✅ به «' . $name . '» اضافه شد' : '▫️ از «' . $name . '» برداشته شد');

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The customer's groups as the panel answered them: how many groups and how many they are in, a switch per group.
     *
     * @param array<string, mixed> $detail
     */
    private function view(Admin $admin, array $detail): View
    {
        $user = Customer::row($detail);
        $id = Customer::int($user, 'id');
        $in = Customer::ids(Json::rows($user, 'groups'));
        $groups = Customer::groups($admin);

        $view = View::make(self::TITLE, ...Customer::under(Customer::name($user)));
        if ($groups === []) {
            $view->line('هنوز گروهی نساخته‌اید؛ اول در «' . Fmt::e(GroupsScreen::TITLE) . '» یک گروه بسازید، بعد مشتری را در آن بگذارید.')
                ->row(Button::to(GroupsScreen::TITLE, GroupsScreen::ID));

            return $view->back(CustomerScreen::ID, Nav::SHOW, $id);
        }

        // The page of switches shown — one a group, in order —: the groups they are in elsewhere are named over it, as
        // their switches do not show.
        // The page kept is this customer's: another's groups open on the first.
        if (($admin->state(self::ID)['user'] ?? null) !== $id) {
            $admin->remember(self::ID, ['user' => $id, 'page' => 1]);
        }
        $page = max(1, (int) ($admin->state(self::ID)['page'] ?? 1));
        // On the seat's own account a «همکار» group they are not in is not offered: it would set their own prices
        // (ActorRefusedException::ownPrices()). The switches offered are paged, the groups all counted.
        $own = Customer::own($admin, $id);
        $offered = $own ? array_values(array_filter($groups, static fn(array $group): bool => !Json::on($group, 'agent_pricing') || in_array(Customer::int($group, 'id'), $in, true))) : $groups;
        $shown = Customer::ids(View::slice($offered, $page, View::CHOICES_MOST)[0]);
        $elsewhere = [];
        $switches = [];
        foreach ($offered as $group) {
            $groupId = Customer::int($group, 'id');
            $member = in_array($groupId, $in, true);
            $partner = Json::on($group, 'agent_pricing');
            $name = (Customer::text($group, 'name') ?? '') . ($partner ? ' · همکار' : '');
            if ($member && !in_array($groupId, $shown, true)) {
                $elsewhere[] = Customer::text($group, 'name') ?? '';
            }
            $switches[] = Button::to(($member ? Words::PICKED : '▫️ ') . $name . ' · ' . Fmt::number(Customer::members($group)) . ' نفر', self::ID, 'sw', $id, $groupId, $member ? 0 : 1);
        }
        $view->total(count($groups), 'گروه', 'عضو ' . Fmt::plain(Fmt::number(count($in))), $elsewhere === [] ? '' : 'در صفحه‌های دیگر: ' . implode('، ', $elsewhere));
        if (count($offered) < count($groups)) {
            $view->note('این حساب خود شماست؛ گذاشتنش در گروه «همکار» با مدیر دیگری است.');
        }

        return $view->help('هر گروه را بزنید تا مشتری در آن گذاشته یا از آن برداشته شود. گروه «همکار»: ' . Fmt::e(GroupsScreen::PARTNER))
            ->paged(1, $switches, $page, static fn(int $at): array => [self::ID, 'pg', [$id, $at]])
            ->back(CustomerScreen::ID, Nav::SHOW, $id);
    }
}
