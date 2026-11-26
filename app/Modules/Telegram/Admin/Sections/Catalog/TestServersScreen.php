<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;
use App\Support\Traffic;

/**
 * «سرورهای اکانت تست» (the tests page's servers section): every server with what a test on it gets and whether it would
 * give one in this shop now — its chip: 🟢 giving, ⚠️ on but not able to now, ⏸ off. A server's own terms are the
 * installation's: for the owner each server is a button that is its row, to its terms (TestServerScreen); for an agent
 * the list only reads, a line each, as their panel's does. The servers go by pages, as the management's lists.
 *
 * Actions: `page` (n).
 */
final class TestServersScreen extends BaseScreen
{
    public const ID = 'tsr';

    public const TITLE = Icons::SERVERS . ' سرورهای اکانت تست';

    public function show(Admin $admin, array $args): View
    {
        $servers = self::servers($admin);
        [$rows, $page, $pages] = View::slice($servers, (int) ($admin->state(self::ID)['page'] ?? 1));
        $giving = array_filter($servers, static fn(array $server): bool => (bool) ($server['available'] ?? false));
        $view = View::make(self::TITLE, ...CatalogFmt::under(Fmt::bare(TestsScreen::TITLE)))
            ->total(count($servers), 'سرور', Fmt::number(count($giving)) . ' اکانت تست می‌دهد');
        if ($servers === []) {
            $view->line()->line('هنوز سروری نیست؛ اکانت تست روی سرورهای فروشگاه ساخته می‌شود، اول سروری اضافه شود.');
        }
        $owner = $admin->seat->isOwner();
        foreach ($rows as $server) {
            $label = self::chip($server) . ' ' . Fmt::cut((string) ($server['name'] ?? ''), 18) . ' · ' . self::terms($server);
            if ($owner) {
                $view->row(Button::to($label, TestServerScreen::ID, 'srv', (int) ($server['id'] ?? 0)));
            } else {
                $view->line(Fmt::e($label));
            }
        }
        $view->pager($page, $pages, self::ID, 'page');

        return $view->help('حجم و مدت اکانت تست هر سرور، و این‌که خود سرور الان در این فروشگاه اکانت تست می‌دهد (' . CatalogFmt::emoji(Words::ACTIVE) . ') یا روشن است ولی فعلا نمی‌دهد (⚠️: غیرفعال است، لینک اشتراک نمی‌دهد یا ظرفیتش پر است؛ در فروشگاه نماینده شاید حجم نماینده هم کافی نباشد) یا خاموش است؛ ⏳ یعنی ساعت‌ها از اولین اتصال مشتری شمرده می‌شوند. روشن بودن اکانت تست، سقف روزانه و سهمیه مشتری‌ها در «' . Fmt::e(Fmt::bare(TestSettingsScreen::TITLE)) . '» جدا حساب می‌شوند.' . ($owner ? '' : ' حجم و مدت هر سرور را مالک فروشگاه اصلی تعیین می‌کند.'))
            ->back(TestsScreen::ID)
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
     * Every server with its test terms and whether it gives a test in this shop now.
     *
     * @return list<array<string, mixed>>
     */
    public static function servers(Admin $admin): array
    {
        return Json::rows($admin->api->get('/tests/servers'), 'servers');
    }

    /**
     * Whether the server would give a test now, as its chip: 🟢 giving, ⚠️ on but not now, ⏸ off.
     *
     * @param array<string, mixed> $server
     */
    private static function chip(array $server): string
    {
        return match (true) {
            (bool) ($server['available'] ?? false) => CatalogFmt::emoji(Words::ACTIVE),
            (bool) ($server['test_enabled'] ?? false) => '⚠️',
            default => CatalogFmt::emoji(Words::OFF),
        };
    }

    /**
     * What a test on the server gets, short: «۵۰۰ مگابایت · ۲۴ ساعت», and whether its hours wait for the first connection.
     *
     * @param array<string, mixed> $server
     */
    private static function terms(array $server): string
    {
        return Fmt::plain(Fmt::bytes((int) ($server['test_traffic_mb'] ?? 0) * Traffic::MEGABYTE)) . ' · ' . Fmt::plain(Fmt::hours((int) ($server['test_duration_hours'] ?? 0))) . ((bool) ($server['test_on_hold'] ?? false) ? ' · ⏳' : '');
    }
}
