<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Screens;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «🖥 سرورها» (the owner's, the panel's «سرورها»): the panels the shop sells on, in the owner's order — a button each to
 * its page (ServerScreen), the button the row: its state as a chip (🟢 connected, 🔴 an error, 🟡 not checked yet, ⏸ off),
 * its name, its active services against its capacity and its inbounds for sale —; over them how many there are and, for
 * a server nothing is sold on now, why; «➕ افزودن سرور». The panel lists them whole: the pages here are cut from that.
 *
 * Actions: `page` (n).
 */
final class ServersScreen extends BaseScreen
{
    public const ID = 'sv';

    public const TITLE = Icons::SERVERS . ' سرورها';

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

        $view = View::make(self::TITLE, Words::ROOT);
        if ($servers === []) {
            $view->line('<b>هنوز سروری اضافه نشده است.</b> اولین پنل را وصل کنید تا بتوانید پلن بسازید و اشتراک بفروشید.');
        } else {
            $failing = count(array_filter($servers, static fn(array $server): bool => ServerWords::state($server) === 'failed'));
            $view->total(count($servers), 'سرور', $failing > 0 ? Fmt::plain(Fmt::number($failing)) . ' با خطا' : '');
        }
        foreach ($shown as $server) {
            if (is_string($server['unsellable_reason'] ?? null)) {
                $view->line('⚠️ <b>' . Fmt::e((string) ($server['name'] ?? '')) . '</b>: ' . Fmt::e($server['unsellable_reason']));
            }
        }
        foreach ($shown as $server) {
            $view->row(Button::to(self::label($server), ServerScreen::ID, 'show', (int) ($server['id'] ?? 0)));
        }
        $view->row(Button::to(Words::add('سرور'), ServerDriversScreen::ID)->success())
            ->pager($page, $pages, self::ID, 'page');

        return $view->help('هر سرور یک پنل (مثل ' . Fmt::ltr('3x-ui') . ' یا ' . Fmt::ltr('Marzban') . ') است که کانفیگ‌های مشتری‌ها روی آن ساخته می‌شود. در صفحه هر سرور: بررسی اتصال، اینباندهای فروش، تنظیمات اتصال، اکانت تست، افزودن زمان و حجم به سرویس‌ها و انتقال سرویس‌ها به سرور دیگر.')
            ->back(Screens::HOME)
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
     * A server's row: its chip, its name, its active services (of its capacity) and its inbounds for sale.
     *
     * @param array<string, mixed> $server
     */
    private static function label(array $server): string
    {
        $counts = Json::part($server, 'counts');
        $active = Fmt::plain(Fmt::number((int) ($counts['active_subscriptions'] ?? 0))) . (is_int($server['capacity'] ?? null) ? '/' . Fmt::plain(Fmt::number($server['capacity'])) : '');

        return ServerWords::dot($server) . ' ' . Fmt::cut((string) ($server['name'] ?? ''), 20) . ' · ' . $active . ' سرویس · ' . Fmt::plain(Fmt::number((int) ($counts['selectable_inbounds'] ?? 0))) . '/' . Fmt::plain(Fmt::number((int) ($counts['inbounds'] ?? 0))) . ' اینباند';
    }
}
