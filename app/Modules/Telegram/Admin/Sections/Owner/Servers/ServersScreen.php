<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * «🖥 سرورها» (the owner's, the panel's «سرورها»): the panels the shop sells on, in the owner's order — each with its
 * connector, address and state, what of it is for sale, its active services against its capacity, its last check and
 * why nothing is sold on it now —, a button each to its page (ServerScreen), and «➕ افزودن سرور». The panel lists them
 * whole: the pages here are cut from that.
 *
 * Actions: `page` (n).
 */
final class ServersScreen extends BaseScreen
{
    public const ID = 'sv';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        // A connection test's answer is shown on its server's page until the list is opened again.
        $admin->remember(ServerScreen::ID, ['probe' => null]);
        $servers = Json::rows($admin->api->get('/servers'), 'servers');
        [$shown, $page, $pages] = View::slice($servers, (int) ($admin->state(self::ID)['page'] ?? 1));

        $view = View::make('🖥 سرورها', 'مدیریت')
            ->line('پنل‌هایی که کانفیگ‌ها روی آن‌ها ساخته می‌شوند؛ هر سرور را باز کنید تا اینباندها، اتصال و افزودن زمان و حجم را ببینید.');
        if ($servers === []) {
            $view->line()->line('<b>هنوز سروری اضافه نشده است</b>')->line('اولین پنل را وصل کنید تا بتوانید پلن بسازید و اشتراک بفروشید.');
        } else {
            $view->line(Fmt::number(count($servers)) . ' سرور' . ($pages > 1 ? ' · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages) : ''));
        }

        $buttons = [];
        foreach ($shown as $server) {
            $counts = Json::part($server, 'counts');
            $capacity = is_int($server['capacity'] ?? null) ? ' / ' . Fmt::number($server['capacity']) : '';
            $view->line()
                ->line('<b>' . Fmt::e((string) ($server['name'] ?? '')) . '</b> · ' . Fmt::ltr((string) ($server['driver_label'] ?? '')) . ' · ' . ServerWords::status($server))
                ->line('   ' . Fmt::ltr(Fmt::cut((string) ($server['base_url'] ?? ''), 50)))
                ->line('   اینباند: ' . Fmt::number((int) ($counts['selectable_inbounds'] ?? 0)) . ' از ' . Fmt::number((int) ($counts['inbounds'] ?? 0)) . ' برای فروش · اشتراک فعال: ' . Fmt::number((int) ($counts['active_subscriptions'] ?? 0)) . $capacity)
                ->line('   آخرین بررسی: ' . Fmt::date(is_string($server['last_checked_at'] ?? null) ? $server['last_checked_at'] : null, true));
            if (is_string($server['unsellable_reason'] ?? null)) {
                $view->line('   ⚠️ ' . Fmt::e($server['unsellable_reason']));
            }
            $buttons[] = Button::to(ServerWords::dot($server) . ' ' . (string) ($server['name'] ?? '') . ' · ' . (string) ($server['driver_label'] ?? ''), ServerScreen::ID, 'show', (int) ($server['id'] ?? 0));
        }
        $view->grid(1, ...$buttons)->pager($page, $pages, self::ID, 'page');

        return $view->row(Button::to('➕ افزودن سرور', ServerDriversScreen::ID))->back(Screens::HOME);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        if ($action === 'page') {
            $admin->remember(self::ID, ['page' => self::page($args)]);
        }

        return $this->show($admin, []);
    }
}
