<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Catalog;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Support\Traffic;

/**
 * «سرورهای اکانت تست» (the tests page's servers section): every server with what a test on it gets and whether it would
 * give one in this shop now — switched on and able to, switched on but not able to now, or off. A server's own terms are
 * the installation's: the owner opens each (TestServerScreen); for an agent the list only reads, as their panel's does.
 * The servers go by pages, as the management's lists.
 *
 * Actions: `page` (n).
 */
final class TestServersScreen extends BaseScreen
{
    public const ID = 'tsr';

    public function allows(AdminSeat $seat): bool
    {
        return $seat->configures();
    }

    public function show(Admin $admin, array $args): View
    {
        $servers = self::servers($admin);
        [$rows, $page, $pages] = View::slice($servers, (int) ($admin->state(self::ID)['page'] ?? 1));
        $view = View::make('🖥 سرورهای اکانت تست', 'مدیریت', 'فروشگاه', 'اکانت تست')
            ->line('حجم و مدت اکانت تست هر سرور و این‌که خود سرور الان در این فروشگاه اکانت تست می‌دهد یا نه؛ روشن بودن اکانت تست، سقف روزانه و سهمیه مشتری‌ها در تنظیمات جدا حساب می‌شوند.');
        if ($servers === []) {
            $view->line()->line('هنوز سروری نیست؛ اکانت تست روی سرورهای فروشگاه ساخته می‌شود، اول سروری اضافه شود.');
        }
        if ($pages > 1) {
            $view->line(Fmt::number(count($servers)) . ' سرور · صفحه ' . Fmt::number($page) . ' از ' . Fmt::number($pages));
        }
        foreach ($rows as $server) {
            $view->line()
                ->line('<b>' . Fmt::e((string) ($server['name'] ?? '')) . '</b> · ' . self::giving($server))
                ->line(Fmt::bytes((int) ($server['test_traffic_mb'] ?? 0) * Traffic::MEGABYTE) . ' · ' . Fmt::hours((int) ($server['test_duration_hours'] ?? 0)) . ' · ' . ((bool) ($server['test_on_hold'] ?? false) ? 'از اولین اتصال' : 'از لحظه ساخت'));
        }
        $view->note('سروری که اکانت تستش روشن است ولی الان نمی‌دهد، غیرفعال است، لینک اشتراک نمی‌دهد یا ظرفیتش پر است؛ در فروشگاه نماینده شاید حجم نماینده هم کافی نباشد.');
        if ($admin->seat->isOwner()) {
            foreach ($rows as $server) {
                $view->row(Button::to('⚙️ ' . (string) ($server['name'] ?? ''), TestServerScreen::ID, 'srv', (int) ($server['id'] ?? 0)));
            }
        }
        $view->pager($page, $pages, self::ID, 'page');

        return $view->back(TestsScreen::ID);
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
     * Whether the server would give a test now, in the panel's words.
     *
     * @param array<string, mixed> $server
     */
    private static function giving(array $server): string
    {
        return match (true) {
            (bool) ($server['available'] ?? false) => '✅ اکانت تست می‌دهد',
            (bool) ($server['test_enabled'] ?? false) => '⚠️ فعلا نمی‌دهد',
            default => '⛔️ خاموش',
        };
    }
}
