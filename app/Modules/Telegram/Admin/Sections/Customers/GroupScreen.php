<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Door\ApiRefusal;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * One customer group (a row of the panel's «گروه‌ها»; it has no route for one: read from the list): a card of its members,
 * its «همکار» switch and its place in the admin's order; its customers on the users list (the screen's main way on); and
 * the group renamed (typed), «همکار» switched, moved up or down, deleted — asked first, red, in the panel's words (its
 * customers only leave it). A group the seat's own account is in (one of the shop's admins') is not switched to
 * «همکار» from here: their own prices are another admin's.
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
                // The list as the card counts it: a search or a pill left on it would hide some (its order stays).
                $admin->remember(UsersScreen::ID, ['group' => $id, 'group_name' => $name, 'page' => 1, 'search' => '', 'f' => []]);

                return $admin->open(UsersScreen::ID);
            case 'name':
                return $admin->ask(GroupsScreen::nameQuestion(self::ID, 'name', [$id], $name));
            case 'ap':
                $on = ($args[1] ?? '') === '1';
                $admin->api->put("/customer-groups/{$id}", ['name' => $name, 'agent_pricing' => $on]);
                $admin->notice($on ? '▶️ «همکار» روشن شد' : '⏸ «همکار» خاموش شد');

                return $this->show($admin, [(string) $id]);
            case 'up':
            case 'down':
                $ids = Customer::ids($groups);
                $at = (int) array_search($id, $ids, true);
                $to = $action === 'up' ? $at - 1 : $at + 1;
                if (isset($ids[$to])) {
                    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
                    $admin->api->post('/customer-groups/reorder', ['ids' => $ids]);
                    $admin->notice('✅ ترتیب ذخیره شد');
                }

                return $this->show($admin, [(string) $id]);
            case 'del':
                $count = Customer::members($group);
                $details = match (true) {
                    $count === 0 => 'هیچ مشتری‌ای در این گروه نیست.',
                    $count === 1 => 'یک مشتری در این گروه است؛ فقط از گروه بیرون می‌آید و حسابش دست نمی‌خورد.',
                    default => Fmt::number($count) . ' مشتری در این گروه هستند؛ فقط از گروه بیرون می‌آیند و حسابشان دست نمی‌خورد.',
                };

                return $admin->confirm(
                    'این گروه حذف شود؟',
                    Button::to(Words::yes('حذف شود'), self::ID, 'gone', $id),
                    Button::to(Words::CANCEL, self::ID, Nav::SHOW, $id),
                    Fmt::e('«' . $name . '» حذف می‌شود.') . "\n" . $details,
                    danger: true,
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
        $groups = Customer::groups($admin);
        foreach ($groups as $group) {
            if (Customer::int($group, 'id') === $id) {
                return [$group, $groups];
            }
        }

        return [null, $groups];
    }

    /**
     * Whether group `$id` holds the seat's own account, one of the shop's admins' (AdminSeat::isAdmin()) — read from
     * their customer page (GET /users/{id}), not remembered as the customer the screens are on; false when it cannot be
     * read.
     */
    private static function holdsOwn(Admin $admin, int $id): bool
    {
        $own = $admin->seat->principal->customer?->id;
        if ($own === null) {
            return false;
        }
        try {
            $groups = Json::rows(Customer::row($admin->api->get('/users/' . $own)), 'groups');
        } catch (ApiRefusal) {
            return false;
        }

        return in_array($id, Customer::ids($groups), true);
    }

    /**
     * @param array<string, mixed> $group
     * @param list<array<string, mixed>> $groups
     */
    private function view(Admin $admin, array $group, array $groups): View
    {
        $id = Customer::int($group, 'id');
        $partner = Json::on($group, 'agent_pricing');
        $ids = Customer::ids($groups);
        $at = (int) array_search($id, $ids, true);

        $view = View::make(Icons::GROUPS . ' ' . (Customer::text($group, 'name') ?? ''), ...GroupsScreen::under(Fmt::bare(GroupsScreen::TITLE)))
            ->card([
                'مشتری‌ها' => Fmt::number(Customer::members($group)) . ' نفر',
                'همکار' => Fmt::onOff($partner),
                'جایگاه' => Fmt::number($at + 1) . ' از ' . Fmt::number(count($ids)),
                'ساخته شده' => Fmt::date(Customer::text($group, 'created_at')),
            ])
            ->row(Button::to(Icons::CUSTOMERS . ' مشتری‌های این گروه', self::ID, 'users', $id)->primary());
        // «همکار» switched on for a group the seat's own account is in would set their own prices
        // (ActorRefusedException::ownPrices()): another admin switches it.
        $mine = !$partner && self::holdsOwn($admin, $id);
        if ($mine) {
            $view->note('حساب خود شما در این گروه است؛ روشن کردن «همکار» آن با مدیر دیگری است.');
        }
        $view->tiles(Button::to(Words::edit('نام'), self::ID, 'name', $id), $mine ? null : Button::to(Words::turn(!$partner, 'همکار'), self::ID, 'ap', $id, $partner ? 0 : 1))
            ->row(
                $at > 0 ? Button::to(Words::UP . ' بالاتر', self::ID, 'up', $id) : null,
                $at < count($ids) - 1 ? Button::to(Words::DOWN . ' پایین‌تر', self::ID, 'down', $id) : null,
            )
            ->row(Button::to(Words::DELETE . ' گروه', self::ID, 'del', $id)->danger());

        return $view->help('همکار: ' . GroupsScreen::PARTNER)->back(GroupsScreen::ID);
    }
}
