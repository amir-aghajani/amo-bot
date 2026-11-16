<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Bot\Texts;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Sections\Bot\BotScreens;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
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
            $texts = $changed ? TextsScreen::reworded($group['texts']) : $group['texts'];
            $mine = count(TextsScreen::reworded($group['texts']));
            [$shown, $page, $pages] = View::slice($texts, self::page($args, 1));
            $admin->remember(self::ID, ['group' => $key, 'page' => $page]);

            $view = View::make(Icons::TEXTS . ' ' . $group['title'], ...[...BotScreens::PATH, Fmt::bare(TextsScreen::TITLE)])
                ->total(count($texts), 'متن', $changed ? 'فقط تغییر یافته' : ($mine > 0 ? Fmt::plain(Fmt::number($mine)) . ' تغییر یافته' : ''));
            if ($texts === []) {
                $view->line()->line('در این گروه متنی تغییر نکرده است.');
            }
            foreach ($shown as $text) {
                $view->row(TextsScreen::button($text));
            }
            $view->pager($page, $pages, self::ID, 'show', $key);

            return $view->note('✏️ تغییر یافته · 📄 متن پیش‌فرض')->back(TextsScreen::ID);
        }

        return $admin->open(TextsScreen::ID);
    }
}
