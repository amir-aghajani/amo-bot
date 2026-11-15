<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Owner\Servers;

use App\Modules\Telegram\Admin\AdminSeat;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * A server's inbounds (the panel's «اینباندها» card): what it sells — its inbounds as its panel last listed them, each
 * with its protocol, port and clients and a switch for sale (one the panel has disabled cannot be chosen, as the panel's
 * switch is held) —, and «🔄 به‌روزرسانی از پنل». Each switch answers the whole list as the API then has it.
 *
 * Actions: `sell` (inbound id, 0/1), `sync`, `page` (n).
 */
final class ServerInboundsScreen extends BaseScreen
{
    public const ID = 'svi';

    private const PER_PAGE = 10;

    public function allows(AdminSeat $seat): bool
    {
        return $seat->isOwner();
    }

    public function show(Admin $admin, array $args): View
    {
        $id = ServerWords::current($admin, $args);
        if ($id === 0) {
            return $admin->open(ServersScreen::ID);
        }
        $answer = $admin->api->get("/servers/{$id}");

        return $this->view($admin, (array) ($answer['server'] ?? []), (array) ($answer['inbounds'] ?? []));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        $id = ServerWords::current($admin, []);
        switch ($action) {
            case 'sell':
                $inbounds = (array) ($admin->api->patch("/servers/{$id}/inbounds/" . self::id($args), ['is_selectable' => self::id($args, 1) === 1])['inbounds'] ?? []);
                $admin->notice(self::id($args, 1) === 1 ? '✅ برای فروش انتخاب شد' : '⏸ از فروش برداشته شد');

                return $this->view($admin, (array) ($admin->api->get("/servers/{$id}")['server'] ?? []), $inbounds);
            case 'sync':
                $answer = $admin->api->post("/servers/{$id}/inbounds/sync");
                $admin->notice('✅ اینباندها به‌روز شدند');

                return $this->view($admin, (array) ($answer['server'] ?? []), (array) ($answer['inbounds'] ?? []));
            case 'page':
                $admin->remember(self::ID, ['page' => self::page($args)]);
                break;
        }

        return $this->show($admin, []);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<mixed> $inbounds
     */
    private function view(Admin $admin, array $server, array $inbounds): View
    {
        $inbounds = array_values(array_filter($inbounds, is_array(...)));
        $selling = count(array_filter($inbounds, static fn(array $row): bool => Json::on($row, 'is_selectable') && Json::on($row, 'enabled')));
        [$shown, $page, $pages] = View::slice($inbounds, (int) ($admin->state(self::ID)['page'] ?? 1), self::PER_PAGE);

        $view = View::make('📡 اینباندها', 'مدیریت', 'سرورها', (string) ($server['name'] ?? ''));
        if ($inbounds === []) {
            $view->line('<b>اینباندی ثبت نشده است</b>')
                ->line('با «به‌روزرسانی از پنل» لیست اینباندها را بگیرید و آن‌هایی را که می‌خواهید بفروشید علامت بزنید.');
        } else {
            $view->line(Fmt::number($selling) . ' از ' . Fmt::number(count($inbounds)) . ' اینباند برای فروش انتخاب شده است.');
        }

        $buttons = [];
        foreach ($shown as $inbound) {
            $enabled = Json::on($inbound, 'enabled');
            $chosen = Json::on($inbound, 'is_selectable');
            $name = ServerWords::inboundName($inbound);
            $view->line()
                ->line(($enabled ? '🟢 ' : '⚪️ ') . '<b>' . Fmt::e($name) . '</b>' . ($enabled ? '' : ' · غیرفعال در پنل') . ($chosen && $enabled ? ' · ✅ برای فروش' : ''))
                ->line('   ' . ServerWords::protocol($inbound) . ' · ' . Fmt::number((int) ($inbound['client_count'] ?? 0)) . ' کلاینت · ' . Fmt::ltr('#' . (string) ($inbound['remote_key'] ?? '') . ' · ' . (string) ($inbound['tag'] ?? '')));
            // A disabled one cannot be chosen: the panel's switch is held for it.
            if ($enabled) {
                $buttons[] = Button::to(($chosen ? '✅ ' : '◻️ ') . $name, self::ID, 'sell', (int) ($inbound['id'] ?? 0), $chosen ? 0 : 1);
            }
        }
        $view->grid(1, ...$buttons)->pager($page, $pages, self::ID, 'page');
        if ($inbounds !== []) {
            $view->note('دکمه هر اینباند آن را برای فروش انتخاب می‌کند یا برمی‌دارد.');
        }

        return $view->row(Button::to('🔄 به‌روزرسانی از پنل', self::ID, 'sync'))->back(ServerScreen::ID, 'show', (int) ($server['id'] ?? 0));
    }
}
