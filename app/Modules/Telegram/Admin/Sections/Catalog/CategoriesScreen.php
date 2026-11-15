<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Auth\StaffGrant;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🗂 دسته‌بندی‌ها» (the panel's «دسته‌بندی‌ها»): the groups plans are filed under, in the order customers see them —
 * each with its plans counted and whether it is shown —, a button to each (CategoryScreen) and, with the catalogue's
 * grant, «➕ افزودن دسته». The panel's route answers every category at once: the pages are cut here.
 *
 * Actions: `page` (n).
 */
final class CategoriesScreen extends BaseScreen
{
    public const ID = 'ct';

    public function show(Admin $admin, array $args): View
    {
        $categories = self::categories($admin);
        [$rows, $page, $pages] = View::slice($categories, (int) ($admin->state(self::ID)['page'] ?? 1));
        $view = View::make('🗂 دسته‌بندی‌ها', 'مدیریت', 'فروشگاه')
            ->line('گروه‌هایی که پلن‌ها زیرشان می‌روند؛ تا وقتی دسته فعالی هست، مشتری در ربات اول دسته را انتخاب می‌کند و پلن‌های بی‌دسته زیر «سایر پلن‌ها» می‌آیند. هر پلن دسته‌اش را در فرم خودش می‌گیرد.')
            ->line(Fmt::number(count($categories)) . ' دسته' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        if ($categories === []) {
            $view->line()->line('<b>هنوز دسته‌ای تعریف نشده است</b>')
                ->line('بدون دسته، مشتری مستقیم لیست پلن‌ها را می‌بیند. دسته‌ها وقتی به کار می‌آیند که پلن‌ها زیاد شوند: ماهانه، سه‌ماهه، اقتصادی…');
        }
        foreach ($rows as $category) {
            $view->row(Button::to(
                ((bool) ($category['is_active'] ?? false) ? '' : '⏸ ') . Fmt::cut((string) $category['name'], 30) . ' · ' . Fmt::number((int) (($category['counts'] ?? [])['plans'] ?? 0)) . ' پلن',
                CategoryScreen::ID,
                'show',
                (int) $category['id'],
            ));
        }
        $view->pager($page, $pages, self::ID, 'page');
        if ($admin->seat->holds(StaffGrant::Catalog)) {
            $view->row(Button::to('➕ افزودن دسته', CategoryScreen::ID, 'new'));
        }

        return $view->back('hs');
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
