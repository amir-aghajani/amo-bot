<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Sections\Customers\UsersScreen;
use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;
use App\Modules\Telegram\Admin\Ui\Words as UiWords;

/**
 * The dashboard's latest arrivals (GET /dashboard's): «🛒 آخرین سفارش‌ها» — each order a row «state #number · what it
 * was on · amount · customer», as the orders list has them — or «👥 کاربران جدید» — each customer a row «name · when
 * they came» —, every row a button to its own screen, and the whole list a tap away.
 *
 * Shown with its kind: ORDERS or USERS.
 */
final class DashboardRecentScreen extends BaseScreen
{
    public const ID = 'dbr';

    public const ORDERS = 'o';
    public const USERS = 'u';

    public const ORDERS_TITLE = Icons::ORDERS . ' آخرین سفارش‌ها';
    public const USERS_TITLE = Icons::CUSTOMERS . ' کاربران جدید';

    public function show(Admin $admin, array $args): View
    {
        $data = DashboardScreen::read($admin, DashboardScreen::range($admin));
        $users = ($args[0] ?? '') === self::USERS;
        $view = $users ? $this->users($admin, $data) : $this->orders($admin, $data);

        return $view->back(DashboardScreen::ID)->refresh(self::ID, Nav::SHOW, $users ? self::USERS : self::ORDERS);
    }

    /** @param array<string, mixed> $data */
    private function orders(Admin $admin, array $data): View
    {
        $view = View::make(self::ORDERS_TITLE, UiWords::ROOT, Fmt::bare(DashboardScreen::TITLE));
        $rows = Json::rows($data, 'recent_orders');
        if ($rows === []) {
            $view->line('هنوز سفارشی ثبت نشده است. اولین سفارش، از ربات یا وب‌سایت، این‌جا نمایش داده می‌شود.');
        } else {
            $view->line(Fmt::number(count($rows)) . ' سفارش آخر، تازه‌ترین اول');
        }
        foreach ($rows as $order) {
            $id = is_int($order['id'] ?? null) ? $order['id'] : 0;
            $user = is_array($order['user'] ?? null) ? $order['user'] : null;
            $view->row(Links::to(
                $admin,
                Sales::mark(Sales::ORDER_STATUS, $order['status'] ?? '') . ' ' . Fmt::id($id) . ' · ' . self::onWhat($order) . ' · ' . Fmt::plain(Fmt::money(Words::figure($order['amount'] ?? null))) . ' · ' . Words::name($user),
                Links::ORDER,
                Nav::SHOW,
                $id,
            ));
        }

        return $view->row(Links::to($admin, Icons::ORDERS . ' همه سفارش‌ها', 'or'));
    }

    /** @param array<string, mixed> $data */
    private function users(Admin $admin, array $data): View
    {
        $view = View::make(self::USERS_TITLE, UiWords::ROOT, Fmt::bare(DashboardScreen::TITLE));
        $rows = Json::rows($data, 'recent_users');
        if ($rows === []) {
            $view->line('هنوز کسی ثبت‌نام نکرده است. کاربرانی که به ربات ' . Fmt::ltr('/start') . ' بزنند یا در وب‌سایت ثبت‌نام کنند این‌جا ظاهر می‌شوند.');
        } else {
            $view->line(Fmt::number(count($rows)) . ' کاربر آخر، تازه‌ترین اول');
        }
        foreach ($rows as $user) {
            $view->row(Links::customer($admin, $user, Words::name($user) . ' · ' . Fmt::plain(Words::ago(is_string($user['created_at'] ?? null) ? $user['created_at'] : null))));
        }

        return $view->row(Links::to($admin, Icons::CUSTOMERS . ' همه کاربران', UsersScreen::ID));
    }

    /**
     * What an order was on: its plan — a service's extra traffic, time or devices said before its service's plan —, else
     * what it was for (plain text).
     *
     * @param array<string, mixed> $order
     */
    private static function onWhat(array $order): string
    {
        $type = (string) ($order['type'] ?? '');
        $kind = Sales::ORDER_TYPE[$type] ?? $type;
        if (!is_string($order['plan'] ?? null) || $order['plan'] === '') {
            return $kind;
        }

        return str_starts_with($type, 'extra_') ? $kind . ' · ' . $order['plan'] : $order['plan'];
    }
}
