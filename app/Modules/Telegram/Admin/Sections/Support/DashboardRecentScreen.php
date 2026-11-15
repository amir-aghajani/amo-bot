<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\BaseScreen;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * The dashboard's latest arrivals (GET /dashboard's): «🛒 آخرین سفارش‌ها» — each order's number, customer, what it was
 * on, state, amount and when — or «👥 مشتریان جدید» — each customer, their wallet and when they came —, every row a
 * button to its own screen, and «مشاهده همه» to the whole list.
 *
 * Shown with its kind: ORDERS or USERS.
 */
final class DashboardRecentScreen extends BaseScreen
{
    public const ID = 'dbr';

    public const ORDERS = 'o';
    public const USERS = 'u';

    /** An order's state (the panel's ORDER_STATUS). */
    public const ORDER_STATUS = [
        'pending' => '⏳ در انتظار پرداخت',
        'paid' => '💳 پرداخت‌شده',
        'processing' => '⚙️ در حال ساخت',
        'fulfilled' => '✅ تکمیل‌شده',
        'failed' => '❌ ناموفق',
        'cancelled' => '✖️ لغوشده',
        'refunded' => '↩️ بازپرداخت‌شده',
    ];

    public function show(Admin $admin, array $args): View
    {
        $data = DashboardScreen::read($admin, DashboardScreen::range($admin));

        return ($args[0] ?? '') === self::USERS ? $this->users($admin, $data) : $this->orders($admin, $data);
    }

    /** @param array<string, mixed> $data */
    private function orders(Admin $admin, array $data): View
    {
        $view = View::make('🛒 آخرین سفارش‌ها', 'مدیریت', 'داشبورد');
        $rows = array_values(array_filter(Json::part($data, 'recent_orders'), is_array(...)));
        if ($rows === []) {
            $view->line('هنوز سفارشی ثبت نشده است. اولین سفارش، از ربات یا وب‌سایت، این‌جا نمایش داده می‌شود.');
        }
        $buttons = [];
        foreach ($rows as $order) {
            $id = is_int($order['id'] ?? null) ? $order['id'] : 0;
            $user = is_array($order['user'] ?? null) ? $order['user'] : null;
            $status = (string) ($order['status'] ?? '');
            $amount = Fmt::money(is_numeric($order['amount'] ?? null) ? (string) $order['amount'] : '0');
            $view->line()
                ->line('<b>' . Fmt::id($id) . '</b> · ' . Words::person($user) . ' · ' . Fmt::e(self::ORDER_STATUS[$status] ?? $status))
                ->line(Fmt::e(self::onWhat($order)) . ' · ' . $amount . ' · ' . Words::ago(is_string($order['created_at'] ?? null) ? $order['created_at'] : null));
            $buttons[] = Links::to($admin, Fmt::id($id) . ' · ' . Words::name($user) . ' · ' . Fmt::plain($amount), Links::ORDER, Nav::SHOW, $id);
        }
        $view->grid(1, ...array_values(array_filter($buttons)));
        $all = Links::to($admin, '🛒 مشاهده همه سفارش‌ها', 'or');
        if ($all !== null) {
            $view->row($all);
        }

        return $view->back(DashboardScreen::ID);
    }

    /** @param array<string, mixed> $data */
    private function users(Admin $admin, array $data): View
    {
        $view = View::make('👥 مشتریان جدید', 'مدیریت', 'داشبورد');
        $rows = array_values(array_filter(Json::part($data, 'recent_users'), is_array(...)));
        if ($rows === []) {
            $view->line('هنوز کسی ثبت‌نام نکرده است. کاربرانی که به ربات ' . Fmt::ltr('/start') . ' بزنند یا در وب‌سایت ثبت‌نام کنند این‌جا ظاهر می‌شوند.');
        }
        $buttons = [];
        foreach ($rows as $user) {
            $view->line('• ' . Words::person($user) . ' · موجودی ' . Fmt::money(is_numeric($user['balance'] ?? null) ? (string) $user['balance'] : '0') . ' · ' . Words::ago(is_string($user['created_at'] ?? null) ? $user['created_at'] : null));
            $buttons[] = Links::customer($admin, $user);
        }
        $view->grid(2, ...array_values(array_filter($buttons)));
        $all = Links::to($admin, '👥 مشاهده همه کاربران', 'us');
        if ($all !== null) {
            $view->row($all);
        }

        return $view->back(DashboardScreen::ID);
    }

    /**
     * What an order was on: its plan — a service's extra traffic, time or devices said before its service's plan —, else
     * what it was for.
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
