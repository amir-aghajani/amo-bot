<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One customer group (a row of the panel's «گروه‌ها»; it has no route for one: read from the list): its members counted,
 * its «همکار» switch and its place in the admin's order; its customers on the users list; and, for a seat the `catalog`
 * grant opens, renamed (typed), «همکار» switched, moved up or down, deleted — asked first, in the panel's words (its
 * customers only leave it).
 *
 * Opened with the group's id. Actions: `users` (id: the users list narrowed to it), `name` (id: asks), `ap` (id, 1|0:
 * «همکار» on or off), `up` / `down` (id), `del` (id: asks), `gone` (id: deleted). Answers: `name` (id).
 */
final class GroupScreen extends BaseScreen
{
    public const ID = 'ugv';

    public function show(Admin $admin, array $args): View
    {
        [$group, $groups] = $this->read($admin, self::id($args));
        if ($group === null) {
            $admin->alert('این گروه دیگر وجود ندارد.');

            return $admin->open(GroupsScreen::ID);
        }

        return $this->view($admin, $group, $groups);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = self::id($args);
        [$group, $groups] = $this->read($admin, $id);
        if ($group === null) {
            return $this->show($admin, $args);
        }
        $name = Customer::text($group, 'name') ?? '';
        switch ($action) {
            case 'users':
                $admin->remember(UsersScreen::ID, ['group' => $id, 'group_name' => $name, 'page' => 1]);

                return $admin->open(UsersScreen::ID);
            case 'name':
                return $admin->ask(new Prompt('نام گروه', self::ID, 'name', [$id], GroupsScreen::NAME_HINT . "\nالان: " . Fmt::e($name)));
            case 'ap':
                $on = ($args[1] ?? '') === '1';
                $admin->api->put("/customer-groups/{$id}", ['name' => $name, 'agent_pricing' => $on]);
                $admin->notice($on ? '✅ «همکار» روشن شد' : '⏸ «همکار» خاموش شد');

                return $this->show($admin, [(string) $id]);
            case 'up':
            case 'down':
                $ids = array_map(static fn(array $row): int => Customer::int($row, 'id'), $groups);
                $at = (int) array_search($id, $ids, true);
                $to = $action === 'up' ? $at - 1 : $at + 1;
                if (isset($ids[$to])) {
                    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
                    $admin->api->post('/customer-groups/reorder', ['ids' => $ids]);
                    $admin->notice('✅ ترتیب ذخیره شد');
                }

                return $this->show($admin, [(string) $id]);
            case 'del':
                $count = is_array($group['counts'] ?? null) ? Customer::int($group['counts'], 'users') : 0;
                $details = match (true) {
                    $count === 0 => 'هیچ مشتری‌ای در این گروه نیست.',
                    $count === 1 => 'یک مشتری در این گروه است؛ فقط از گروه بیرون می‌آید و حسابش دست نمی‌خورد.',
                    default => Fmt::number($count) . ' مشتری در این گروه هستند؛ فقط از گروه بیرون می‌آیند و حسابشان دست نمی‌خورد.',
                };

                return $admin->confirm(
                    'این گروه حذف شود؟',
                    Button::to('🗑 بله، حذف شود', self::ID, 'gone', $id),
                    Button::to('✖️ انصراف', self::ID, 'show', $id),
                    Fmt::e('«' . $name . '» حذف می‌شود.') . "\n" . $details,
                );
            case 'gone':
                $admin->api->delete("/customer-groups/{$id}");
                $admin->notice('🗑 گروه حذف شد');

                return $admin->open(GroupsScreen::ID);
        }

        return $this->show($admin, [(string) $id]);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        $id = self::id($args);
        [$group] = $this->read($admin, $id);
        if ($action !== 'name' || $group === null) {
            return $this->show($admin, $args);
        }
        $admin->api->put("/customer-groups/{$id}", ['name' => $answer->text(), 'agent_pricing' => Json::on($group, 'agent_pricing')]);
        $admin->notice('✅ گروه ذخیره شد');

        return $this->show($admin, [(string) $id]);
    }

    /**
     * The group of `$id` and every group in order, as the panel lists them; the group null when it is gone.
     *
     * @return array{0: array<string, mixed>|null, 1: list<array<string, mixed>>}
     */
    private function read(Admin $admin, int $id): array
    {
        $groups = Json::rows($admin->api->get('/customer-groups'), 'groups');
        foreach ($groups as $group) {
            if (Customer::int($group, 'id') === $id) {
                return [$group, $groups];
            }
        }

        return [null, $groups];
    }

    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $groups
     */
    private function view(Admin $admin, array $group, array $groups): View
    {
        $id = Customer::int($group, 'id');
        $partner = Json::on($group, 'agent_pricing');
        $count = is_array($group['counts'] ?? null) ? Customer::int($group['counts'], 'users') : 0;
        $ids = array_map(static fn(array $row): int => Customer::int($row, 'id'), $groups);
        $at = (int) array_search($id, $ids, true);

        $view = View::make('👫 ' . (Customer::text($group, 'name') ?? ''), 'مدیریت', 'فروشگاه', 'گروه‌های کاربران')
            ->facts([
                'مشتری‌ها' => $count > 0 ? Fmt::number($count) . ' نفر' : 'هیچ مشتری‌ای در این گروه نیست',
                'همکار' => Fmt::onOff($partner),
                'جایگاه' => Fmt::number($at + 1) . ' از ' . Fmt::number(count($ids)),
                'ساخته شده' => Fmt::date(Customer::text($group, 'created_at')),
            ])
            ->note('همکار: ' . GroupsScreen::PARTNER)
            ->row(Button::to('👥 مشتری‌های این گروه', self::ID, 'users', $id));
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $view->row(Button::to('✏️ تغییر نام', self::ID, 'name', $id), Button::to('🤝 همکار: ' . Fmt::onOff($partner), self::ID, 'ap', $id, $partner ? 0 : 1))
                ->row(
                    $at > 0 ? Button::to('🔼 بالاتر', self::ID, 'up', $id) : null,
                    $at < count($ids) - 1 ? Button::to('🔽 پایین‌تر', self::ID, 'down', $id) : null,
                )
                ->row(Button::to('🗑 حذف گروه', self::ID, 'del', $id));
        }

        return $view->back(GroupsScreen::ID);
    }
}
