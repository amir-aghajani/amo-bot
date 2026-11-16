<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
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
 * «🗂 دسته‌بندی‌ها» (the panel's «دسته‌بندی‌ها»): the groups plans are filed under, in the order customers see them,
 * each a button that is its row — its chip, name and how many plans — to its page (CategoryScreen); with the
 * catalogue's grant, «➕ افزودن دسته»; how categories work folded away. The panel's route answers every category at
 * once: the pages are cut here.
 *
 * Actions: `page` (n).
 */
final class CategoriesScreen extends BaseScreen
{
    public const ID = 'ct';

    public const TITLE = Icons::CATEGORIES . ' دسته‌بندی‌ها';

    public function show(Admin $admin, array $args): View
    {
        $categories = self::categories($admin);
        [$rows, $page, $pages] = View::slice($categories, (int) ($admin->state(self::ID)['page'] ?? 1));
        $on = array_filter($categories, static fn(array $category): bool => (bool) ($category['is_active'] ?? false));
        $view = View::make(self::TITLE, ...CatalogFmt::under())
            ->total(count($categories), 'دسته', $categories === [] ? '' : Fmt::number(count($on)) . ' فعال');
        if ($categories === []) {
            $view->line()->line('<b>هنوز دسته‌ای تعریف نشده است</b>')
                ->line('بدون دسته، مشتری مستقیم لیست پلن‌ها را می‌بیند. دسته‌ها وقتی به کار می‌آیند که پلن‌ها زیاد شوند: ماهانه، سه‌ماهه، اقتصادی…');
        }
        foreach ($rows as $category) {
            $view->row(Button::to(
                CatalogFmt::dot((bool) ($category['is_active'] ?? false)) . ' ' . Fmt::cut((string) $category['name'], 30) . ' · ' . Fmt::plain(Fmt::number((int) (($category['counts'] ?? [])['plans'] ?? 0))) . ' پلن',
                CategoryScreen::ID,
                Nav::SHOW,
                (int) $category['id'],
            ));
        }
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $view->row(Button::to(Words::add('دسته'), CategoryScreen::ID, 'new')->success());
        }
        $view->pager($page, $pages, self::ID, 'page');

        return $view->help('تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند و پلن‌های بی‌دسته زیر «سایر پلن‌ها» می‌آیند. هر پلن دسته‌اش را در فرم خودش می‌گیرد؛ ترتیب همین لیست ترتیب نمایش است و هر دسته در صفحه خودش با 🔼 و 🔽 جابه‌جا می‌شود.')
            ->back(CatalogFmt::HUB)
            ->refresh(self::ID);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'page') {
            $admin->remember(self::ID, ['page' => self::page($args)]);
        }

        return $this->show($admin, []);
    }

    /**
     * Every category, in display order, as the panel lists them.
     *
     * @return list<array<string, mixed>>
     */
    public static function categories(Admin $admin): array
    {
        return Json::rows($admin->api->get('/plans/categories'), 'categories');
    }
}
