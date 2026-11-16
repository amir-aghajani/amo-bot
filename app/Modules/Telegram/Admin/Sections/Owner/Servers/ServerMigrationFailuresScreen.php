<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * «سرویس‌های ناموفق» of a move (the panel's failures dialog): the services it could not carry, the latest first — which
 * client, of which shop, and why —, a page at a time.
 *
 * Actions: `page` (job, n).
 */
final class ServerMigrationFailuresScreen extends BaseScreen
{
    public const ID = 'svmf';

    private const PER_PAGE = 10;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        return $this->view($admin, self::id($args), 1);
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        return $this->view($admin, self::id($args), self::page($args, 1));
    }

    private function view(Admin $admin, int $job, int $page): View
    {
        $server = ServerWords::current($admin, []);
        $answer = $admin->api->get("/servers/{$server}/migrations/{$job}");
        $failures = Json::rows($answer, 'failures');
        [$shown, $page, $pages] = View::slice($failures, $page, self::PER_PAGE);

        $view = View::make('❌ سرویس‌های ناموفق', Words::ROOT, Fmt::bare(ServersScreen::TITLE), Fmt::bare(ServerMigrationsScreen::TITLE))
            ->line('انتقال به «' . Fmt::e((string) ($answer['migration']['target']['name'] ?? '')) . '» · ' . Fmt::number(count($failures)) . ' سرویس');
        foreach ($shown as $failure) {
            $shop = is_array($failure['shop'] ?? null) ? ' · ' . Fmt::e((string) ($failure['shop']['name'] ?? '')) : '';
            $view->line()
                ->line(Fmt::code((string) ($failure['client'] ?? '')) . $shop)
                ->line('   ' . Fmt::e((string) ($failure['reason'] ?? '')));
        }

        return $view->pager($page, $pages, self::ID, 'page', $job)->back(ServerMigrationsScreen::ID);
    }
}
