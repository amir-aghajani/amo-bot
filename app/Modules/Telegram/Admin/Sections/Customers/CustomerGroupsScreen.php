<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Door\BotAdminDoor;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * Which of the admin's groups a customer is in (the panel's «گروه‌ها» card and dialog): a switch per group — ✅ they are
 * in it —, each naming the state it asks for, saved at a tap as the panel saves the dialog (PUT /users/{id}/groups, every
 * group they are in). A «همکار» group sets their prices as the plans do: one they are not in yet asks the `catalog`
 * grant, so for a seat without it that switch is locked. One of the shop's admins only looks at a bot admin's or an
 * agent's groups. Many groups go by pages, the page kept while switches are tapped.
 *
 * Opened with the customer's id. Actions: `sw` (id, group, 1|0: in or out), `no` (id: a «همکار» switch the seat may not
 * use), `pg` (id, n: the groups' page n). State: `page`.
 */
final class CustomerGroupsScreen extends BaseScreen
{
    public const ID = 'usg';

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
        if ($action === 'no') {
            $admin->alert('گروه «همکار» قیمت‌های مشتری را عوض می‌کند. ' . BotAdminDoor::NOT_GRANTED);

            return $this->show($admin, [(string) $id]);
        }
        if ($action === 'pg') {
            $admin->remember(self::ID, ['page' => self::page($args, 1)]);

            return $this->show($admin, [(string) $id]);
        }
        if ($action !== 'sw' || $id === 0) {
            return $this->show($admin, $args);
        }

        $group = self::id($args, 1);
        $in = ($args[2] ?? '') === '1';
        $user = Customer::row(Customer::read($admin, $id));
        $ids = array_map(static fn(array $row): int => Customer::int($row, 'id'), Json::rows($user, 'groups'));
        $next = $in ? array_values(array_unique([...$ids, $group])) : array_values(array_diff($ids, [$group]));
        if ($next !== $ids) {
            $user = Customer::row($admin->api->put("/users/{$id}/groups", ['group_ids' => $next]));
        }
        $name = '';
        foreach (Json::rows($admin->api->get('/customer-groups'), 'groups') as $row) {
            if (Customer::int($row, 'id') === $group) {
                $name = Customer::text($row, 'name') ?? '';
            }
        }
        $admin->notice($in ? '✅ به «' . $name . '» اضافه شد' : '▫️ از «' . $name . '» برداشته شد');

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The customer's groups as the panel answered them, with a switch per group the seat may use.
     *
     * @param array<string, mixed> $detail
     */
    private function view(Admin $admin, array $detail): View
    {
        $user = Customer::row($detail);
        $id = Customer::int($user, 'id');
        $in = array_map(static fn(array $row): int => Customer::int($row, 'id'), Json::rows($user, 'groups'));
        $groups = Json::rows($admin->api->get('/customer-groups'), 'groups');
        $lock = Customer::lock($admin, $detail);
        $catalog = $admin->seat->holds(StaffGrant::Catalog);

        $view = View::make('👫 گروه‌ها', 'مدیریت', 'فروش', 'کاربران', Customer::name($user));
        if ($groups === []) {
            $view->line('هنوز گروهی نساخته‌اید؛ اول در بخش «گروه‌ها» یک گروه بسازید، بعد مشتری را در آن بگذارید.')
                ->row(Button::to('👫 گروه‌های کاربران', GroupsScreen::ID));

            return $view->back(CustomerScreen::ID, 'show', $id);
        }

        $names = [];
        $switches = [];
        foreach ($groups as $group) {
            $groupId = Customer::int($group, 'id');
            $member = in_array($groupId, $in, true);
            $partner = Json::on($group, 'agent_pricing');
            $name = (Customer::text($group, 'name') ?? '') . ($partner ? ' · همکار' : '');
            $count = is_array($group['counts'] ?? null) ? Customer::int($group['counts'], 'users') : 0;
            if ($member) {
                $names[] = Fmt::e($name);
            }
            if ($partner && !$member && !$catalog) {
                $switches[] = Button::to('🔒 ' . $name, self::ID, 'no', $id);
                continue;
            }
            $switches[] = Button::to(($member ? '✅ ' : '▫️ ') . $name . ' · ' . Fmt::number($count) . ' نفر', self::ID, 'sw', $id, $groupId, $member ? 0 : 1);
        }
        $view->line($names === [] ? 'در هیچ گروهی نیست.' : 'در: ' . implode('، ', $names));
        if ($lock !== null) {
            $view->line()->line($lock);

            return $view->back(CustomerScreen::ID, 'show', $id);
        }

        return $view->note('هر گروه را بزنید تا مشتری در آن گذاشته یا از آن برداشته شود. مشتری‌های گروه «همکار» قیمت نماینده پلن‌ها را می‌پردازند.')
            ->paged(1, $switches, max(1, (int) ($admin->state(self::ID)['page'] ?? 1)), static fn(int $at): array => [self::ID, 'pg', [$id, $at]])
            ->back(CustomerScreen::ID, 'show', $id);
    }
}
