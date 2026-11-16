<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Core;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * The middle button of every list's pages («۲ / ۵», View::pages()): tapped, it says «صفحه ۲ از ۵» in a toast and
 * leaves the window as it is — never a dead button, never a redraw.
 *
 * Shown with: page, pages.
 */
final class PageScreen extends BaseScreen
{
    public function show(Admin $admin, array $args): View
    {
        $page = max(1, self::id($args));
        $admin->notice(Words::page($page, max($page, self::id($args, 1))));

        return View::stay();
    }
}
