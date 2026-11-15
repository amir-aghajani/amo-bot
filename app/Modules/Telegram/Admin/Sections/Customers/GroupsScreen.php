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
 * «👫 گروه‌های کاربران» (the users page's «گروه‌ها» section): the admin's customer groups in their order, each with its
 * members counted and «همکار» marked (agent prices), opening the group's own screen (GroupScreen); and, for a seat the
 * `catalog` grant opens, «➕ افزودن گروه» — its name typed, «همکار» switched on the form, then one POST.
 *
 * The panel lists every group at once (no pages): this screen pages them itself. Actions: `page` (n), `add` (the name
 * asked; `new` for a new group, else the draft's again), `ap` (1|0: the draft's «همکار» on or off), `make` (added).
 * Answers: `name`.
 */
final class GroupsScreen extends BaseScreen
{
    public const ID = 'ug';

    /** What a «همکار» group's members pay, in the panel's words. */
    public const PARTNER = 'مشتری‌های این گروه مثل نماینده‌ها قیمت نماینده پلن‌ها را می‌پردازند.';

    /** What a group's name is for, as the panel's form says. */
    public const NAME_HINT = 'فقط برای شما؛ مشتری آن را نمی‌بیند. پیام همگانی را می‌شود فقط برای یک گروه فرستاد.';

    public function show(Admin $admin, array $args): View
    {
        $groups = Json::rows($admin->api->get('/customer-groups'), 'groups');
        [$shown, $page, $pages] = View::slice($groups, (int) ($admin->state(self::ID)['page'] ?? 1));

        $view = View::make('👫 گروه‌های کاربران', 'مدیریت', 'فروشگاه')
            ->line('مشتری‌ها را برای خودتان دسته کنید — ' . Fmt::ltr('VIP') . '، همکاران، یک کمپین —؛ پیام همگانی را می‌شود فقط برای یک گروه فرستاد.')
            ->line(Fmt::number(count($groups)) . ' گروه' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($groups === []) {
            $view->line()->line('هنوز گروهی ندارید. مشتری‌ها را از صفحه هر مشتری، «👫 گروه‌ها»، به گروه اضافه کنید.');
        }
        foreach ($shown as $group) {
            $count = is_array($group['counts'] ?? null) ? Customer::int($group['counts'], 'users') : 0;
            $view->row(Button::to((Customer::text($group, 'name') ?? '') . ' · ' . Fmt::number($count) . ' نفر' . (Json::on($group, 'agent_pricing') ? ' · 🤝 همکار' : ''), GroupScreen::ID, 'show', Customer::int($group, 'id')));
        }
        $view->pager($page, $pages, self::ID, 'page');
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $view->row(Button::to('➕ افزودن گروه', self::ID, 'add', 'new'));
        }

        return $view->back('hs');
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        switch ($action) {
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
            case 'add':
                if (($args[0] ?? '') === 'new') {
                    $admin->remember(self::ID, ['name' => null, 'partner' => false]);
                }

                return $admin->ask(new Prompt('نام گروه', self::ID, 'name', [], self::NAME_HINT . "\nمثلا «" . Fmt::ltr('VIP') . '» یا «همکاران».'));
            case 'ap':
                $draft = $admin->state(self::ID);
                if (is_string($draft['name'] ?? null)) {
                    $admin->remember(self::ID, ['partner' => ($args[0] ?? '') === '1']);

                    return $this->draft($admin);
                }
                break;
            case 'make':
                $draft = $admin->state(self::ID);
                if (!is_string($draft['name'] ?? null)) {
                    break;
                }
                $group = $admin->api->post('/customer-groups', ['name' => $draft['name'], 'agent_pricing' => Json::on($draft, 'partner')])['group'] ?? [];
                $admin->remember(self::ID, ['name' => null, 'partner' => false]);
                $admin->notice('✅ گروه اضافه شد');

                return $admin->open(GroupScreen::ID, 'show', [is_array($group) ? Customer::int($group, 'id') : 0]);
        }

        return $this->show($admin, []);
    }

    public function input(Admin $admin, string $action, array $args, Answer $answer): View
    {
        if ($action !== 'name') {
            return $this->show($admin, []);
        }
        $admin->remember(self::ID, ['name' => $answer->text(), 'partner' => ($admin->state(self::ID)['partner'] ?? false) === true]);

        return $this->draft($admin);
    }

    /** The new group said once more: its name, its «همکار» switch to flip, «✔️ افزودن گروه». */
    private function draft(Admin $admin): View
    {
        $draft = $admin->state(self::ID);
        $partner = Json::on($draft, 'partner');

        return View::make('➕ افزودن گروه', 'مدیریت', 'فروشگاه', 'گروه‌های کاربران')
            ->facts([
                'نام گروه' => Fmt::e(is_string($draft['name'] ?? null) ? $draft['name'] : ''),
                'همکار' => Fmt::onOff($partner),
            ])
            ->note(self::PARTNER)
            ->row(Button::to('🤝 همکار: ' . Fmt::onOff($partner), self::ID, 'ap', $partner ? 0 : 1))
            ->row(Button::to('✔️ افزودن گروه', self::ID, 'make'))
            ->row(Button::to('✏️ نام', self::ID, 'add'), Button::to('✖️ انصراف', self::ID));
    }
}
