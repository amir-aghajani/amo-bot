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
use App\Modules\Telegram\Admin\Ui\Words;

/**
 * A server's inbounds (the panel's «اینباندها» card): what it sells — its inbounds as its panel last listed them, a
 * button each, the row: its «فروش» as a chip (🟢 for sale, ⏸ not), its name, protocol, port and clients; one the panel
 * has disabled is said without a button (it cannot be chosen, as the panel's switch is held) —, and «🔄 خواندن دوباره از
 * پنل». Each switch answers the whole list as the API then has it; a panel that builds a user in one inbound only
 * (Rebecca) takes one for sale at a time, and the API's refusal of a second says so. Each button names the server it
 * shows — an older window's acts on its own server, whichever server's page was opened since —; one without it (an
 * older version's) acts on the server last opened.
 *
 * Actions: `sell` (inbound id, 0/1, server id), `sync` (server id), `page` (server id, n — an older one n alone).
 */
final class ServerInboundsScreen extends BaseScreen
{
    public const ID = 'svi';

    public const TITLE = '📡 اینباندها';

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
        ServerWords::opened($admin, $id);
        if ((int) ($admin->state(self::ID)['server'] ?? 0) !== $id) {
            // Another server's list starts at its first page.
            $admin->remember(self::ID, ['server' => $id, 'page' => 1]);
        }
        $answer = $admin->api->get("/servers/{$id}");

        return $this->view($admin, (array) ($answer['server'] ?? []), (array) ($answer['inbounds'] ?? []));
    }

    public function act(Admin $admin, string $action, array $args): View
    {
        // The server the button names, else the one last opened.
        $id = ServerWords::current($admin, [match ($action) {
            'sell' => $args[2] ?? '',
            'page' => count($args) > 1 ? $args[0] : '',
            default => $args[0] ?? '',
        }]);
        if ($id === 0) {
            return $admin->open(ServersScreen::ID);
        }
        ServerWords::opened($admin, $id);
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
                $admin->remember(self::ID, ['server' => $id, 'page' => self::page($args, max(0, count($args) - 1))]);
                break;
        }

        return $this->show($admin, [(string) $id]);
    }

    /**
     * @param array<string, mixed> $server
     * @param array<mixed> $inbounds
     */
    private function view(Admin $admin, array $server, array $inbounds): View
    {
        $id = (int) ($server['id'] ?? 0);
        $inbounds = array_values(array_filter($inbounds, is_array(...)));
        $selling = count(array_filter($inbounds, static fn(array $row): bool => Json::on($row, 'is_selectable') && Json::on($row, 'enabled')));
        [$shown, $page, $pages] = View::slice($inbounds, (int) ($admin->state(self::ID)['page'] ?? 1), self::PER_PAGE);

        $view = View::make(self::TITLE, Words::ROOT, Fmt::bare(ServersScreen::TITLE), (string) ($server['name'] ?? ''));
        if ($inbounds === []) {
            $view->line('<b>اینباندی ثبت نشده است.</b> با «🔄 خواندن دوباره از پنل» فهرست اینباندها را بگیرید و آن‌هایی را که می‌خواهید بفروشید روشن کنید.');
        } else {
            $view->total(count($inbounds), 'اینباند', Fmt::plain(Fmt::number($selling)) . ' برای فروش');
        }

        $buttons = [];
        foreach ($shown as $inbound) {
            $chosen = Json::on($inbound, 'is_selectable');
            $name = ServerWords::inboundName($inbound);
            // A disabled one cannot be chosen: the panel's switch is held for it — said, with no button.
            if (!Json::on($inbound, 'enabled')) {
                $view->line(Words::INACTIVE . ' <b>' . Fmt::e($name) . '</b> · ' . ServerWords::protocol($inbound) . ' — در پنل خاموش است؛ برای فروش، اول در پنل روشنش کنید.');

                continue;
            }
            $buttons[] = Button::to(($chosen ? '🟢 ' : '⏸ ') . $name . ' · ' . Fmt::plain(ServerWords::protocol($inbound)) . ' · ' . Fmt::plain(Fmt::number((int) ($inbound['client_count'] ?? 0))) . ' کلاینت', self::ID, 'sell', (int) ($inbound['id'] ?? 0), $chosen ? 0 : 1, $id);
        }
        $view->grid(1, ...$buttons)
            ->row(Button::to('🔄 خواندن دوباره از پنل', self::ID, 'sync', $id))
            ->pager($page, $pages, self::ID, 'page', $id);
        if ($inbounds !== []) {
            $view->note('🟢 برای فروش · ⏸ فروخته نمی‌شود؛ دکمه هر اینباند آن را عوض می‌کند.');
        }

        return $view->help('مشتری فقط روی اینباندهای «برای فروش» سرویس می‌گیرد؛ کلاینت‌های فعلی اینباندی که از فروش برداشته شود دست نمی‌خورند. بعضی پنل‌ها (مثل ' . Fmt::ltr('Rebecca') . ') هر کاربر را فقط در یک سرویس می‌سازند؛ آن‌جا یک اینباند برای فروش است و برای فروش اینباند دیگر، اول علامت فروش اینباند فعلی را بردارید.')
            ->back(ServerScreen::ID, 'show', $id)
            ->refresh(self::ID, 'show', $id);
    }
}
