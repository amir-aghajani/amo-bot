<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One group of «متن‌های ربات» (the panel's card of it): its texts under the tab in force (TextsScreen's), paged, each
 * opening its own screen (TextScreen) — the ones reworded marked ✏️.
 *
 * Opened with: the group's key, and the page.
 */
final class TextGroupScreen extends BaseScreen
{
    public const ID = 'txg';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $key = $args[0] ?? '';
        $changed = ($admin->state(TextsScreen::ID)['f'] ?? 'all') === 'changed';
        foreach (TextsScreen::groups($admin) as $group) {
            if ($group['key'] !== $key) {
                continue;
            }
            $texts = $changed ? array_values(array_filter($group['texts'], static fn(array $text): bool => Json::on($text, 'customized'))) : $group['texts'];
            $mine = count(array_filter($group['texts'], static fn(array $text): bool => Json::on($text, 'customized')));
            [$shown, $page, $pages] = View::slice($texts, self::page($args, 1));
            $admin->remember(self::ID, ['group' => $key, 'page' => $page]);

            $view = View::make('📝 ' . $group['title'], 'مدیریت', 'ربات', 'متن‌های ربات')
                ->line(Fmt::number(count($group['texts'])) . ' متن' . ($mine > 0 ? ' · ' . Fmt::number($mine) . ' تغییر یافته' : '') . ($changed ? ' · فقط تغییر یافته‌ها' : '') . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
            if ($texts === []) {
                $view->line()->line('در این گروه متنی تغییر نکرده است.');
            }
            foreach ($shown as $text) {
                $view->row(TextsScreen::button($text));
            }
            $view->pager($page, $pages, self::ID, 'show', $key);

            return $view->back(TextsScreen::ID);
        }

        return $admin->open(TextsScreen::ID);
    }
}
