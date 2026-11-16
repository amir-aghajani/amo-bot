<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Sections\Catalog\CatalogFmt;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Answer;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\Prompt;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Modules\Users\Services\CustomerGroups;
use App\Support\Persian;

/**
 * «👫 گروه‌های کاربران» (the users page's «گروه‌ها» section): the admin's customer groups in their order, how many over
 * them, each a button with its members counted and «همکار» marked (agent prices), opening the group's own screen
 * (GroupScreen); and, for a seat the `catalog` grant opens, «➕ افزودن گروه» — its name typed, «همکار» switched on the
 * form, then one POST.
 *
 * The panel lists every group at once (no pages): this screen pages them itself. Actions: `page` (n), `add` (the name
 * asked; `new` for a new group, else the draft's again), `ap` (1|0: the draft's «همکار» on or off), `make` (added).
 * Answers: `name`.
 */
final class GroupsScreen extends BaseScreen
{
    public const ID = 'ug';

    public const TITLE = Icons::GROUPS . ' گروه‌های کاربران';

    /** What a «همکار» group's members pay, in the panel's words. */
    public const PARTNER = 'مشتری‌های این گروه مثل نماینده‌ها قیمت نماینده پلن‌ها را می‌پردازند.';

    /** What a group's name is for, as the panel's form says. */
    public const NAME_HINT = 'فقط برای شما؛ مشتری آن را نمی‌بیند. پیام همگانی را می‌شود فقط برای یک گروه فرستاد.';

    /**
     * The sections the group screens are under, then `$more`: «مدیریت › فروشگاه › …».
     *
     * @return list<string>
     */
    public static function under(string ...$more): array
    {
        return [Words::ROOT, 'فروشگاه', ...array_values($more)];
    }

    /**
     * A group's name asked: its value now (a rename, a draft's again), an example and the panel's bound.
     *
     * @param list<int|string> $args
     */
    public static function nameQuestion(string $screen, string $action, array $args, ?string $now): Prompt
    {
        $prompt = (new Prompt('نام گروه', $screen, $action, $args, self::NAME_HINT))
            ->example('VIP')
            ->limits('تا ' . Persian::number(CustomerGroups::NAME_MAX) . ' نویسه');

        return $now === null || $now === '' ? $prompt : $prompt->current($now);
    }

    public function show(Admin $admin, array $args): View
    {
        $groups = Customer::groups($admin);
        [$shown, $page, $pages] = View::slice($groups, (int) ($admin->state(self::ID)['page'] ?? 1));
        $catalog = $admin->seat->holds(StaffGrant::Catalog);

        $view = View::make(self::TITLE, ...self::under())->total(count($groups), 'گروه');
        if ($groups === []) {
            $view->line()->line('هنوز گروهی ندارید.');
        }
        foreach ($shown as $group) {
            $view->row(Button::to(self::label($group), GroupScreen::ID, Nav::SHOW, Customer::int($group, 'id')));
        }
        if ($catalog) {
            $view->row(Button::to(Words::add('گروه'), self::ID, 'add', 'new')->success());
        }

        return $view->pager($page, $pages, self::ID, 'page')
            ->help('مشتری‌ها را برای خودتان دسته کنید — ' . Fmt::ltr('VIP') . '، همکاران، یک کمپین —؛ پیام همگانی را می‌شود فقط برای یک گروه فرستاد. مشتری را از صفحه خودش، «' . Icons::GROUPS . ' گروه‌ها»، در گروه بگذارید.' . "\n" . 'گروه «همکار»: ' . self::PARTNER)
            ->back(CatalogFmt::HUB)
            ->refresh(self::ID);
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
                $name = $admin->state(self::ID)['name'] ?? null;

                return $admin->ask(self::nameQuestion(self::ID, 'name', [], is_string($name) ? $name : null));
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

                return $admin->open(GroupScreen::ID, Nav::SHOW, [is_array($group) ? Customer::int($group, 'id') : 0]);
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

    /**
     * A group as its button: its name, its members, «همکار» marked.
     *
     * @param array<string, mixed> $group
     */
    private static function label(array $group): string
    {
        return (Customer::text($group, 'name') ?? '') . ' · ' . Fmt::plain(Fmt::number(Customer::members($group))) . ' نفر' . (Json::on($group, 'agent_pricing') ? ' · ' . Icons::AGENTS . ' همکار' : '');
    }

    /** The new group said once more: its name and «همکار» as a card, the switch to flip, «✔️ ساختن». */
    private function draft(Admin $admin): View
    {
        $draft = $admin->state(self::ID);
        $partner = Json::on($draft, 'partner');

        return View::make(Words::add('گروه'), ...self::under(Fmt::bare(self::TITLE)))
            ->transient()
            ->card([
                'نام گروه' => Fmt::e(is_string($draft['name'] ?? null) ? $draft['name'] : ''),
                'همکار' => Fmt::onOff($partner),
            ])
            ->note('همکار: ' . self::PARTNER)
            ->row(Button::to(Words::turn(!$partner, 'همکار'), self::ID, 'ap', $partner ? 0 : 1))
            ->row(Button::to(Words::CREATE, self::ID, 'make')->success())
            ->row(Button::to(Words::edit('نام'), self::ID, 'add'), Button::to(Words::CANCEL, self::ID));
    }
}
