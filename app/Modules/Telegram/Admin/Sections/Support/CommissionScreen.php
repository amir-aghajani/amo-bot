<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Support;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Admin;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Json;
use App\Modules\Telegram\Admin\Ui\Nav;
use App\Modules\Telegram\Admin\Ui\View;

/**
 * One commission (a row of CommissionsScreen's page): what it paid and at what rate, to whom, from whose payment and
 * order, and when — with the ways to that payment, that order and both customers.
 */
final class CommissionScreen extends KeptRowScreen
{
    public const ID = 'rfcv';

    protected function list(): string
    {
        return CommissionsScreen::ID;
    }

    protected function view(Admin $admin, array $row): View
    {
        $referrer = is_array($row['referrer'] ?? null) ? $row['referrer'] : null;
        $customer = is_array($row['customer'] ?? null) ? $row['customer'] : null;
        $payment = Json::part($row, 'payment');
        $order = Json::part($row, 'order');
        $paymentId = is_int($payment['id'] ?? null) ? $payment['id'] : 0;
        $orderId = is_int($order['id'] ?? null) ? $order['id'] : 0;
        $type = (string) ($order['type'] ?? '');
        $created = is_string($row['created_at'] ?? null) ? $row['created_at'] : null;

        $view = View::make('🧾 پورسانت ' . Fmt::id(is_int($row['id'] ?? null) ? $row['id'] : null), 'مدیریت', 'فروش', 'زیرمجموعه‌گیری', 'پورسانت‌ها')
            ->facts([
                'پورسانت' => '<b>' . Fmt::money(is_numeric($row['commission'] ?? null) ? (string) $row['commission'] : '0') . '</b> (' . Fmt::percent(is_numeric($row['rate'] ?? null) ? $row['rate'] : 0) . ')',
                'معرف' => Words::account($referrer),
                'زیرمجموعه' => Words::account($customer),
                'پرداخت' => Fmt::id($paymentId) . ' · ' . Fmt::money(is_numeric($payment['amount'] ?? null) ? (string) $payment['amount'] : '0'),
                'سفارش' => $orderId > 0 ? Fmt::id($orderId) . ' · ' . Fmt::e(Sales::ORDER_TYPE[$type] ?? $type) : null,
                'زمان' => Fmt::date($created, true) . ' (' . Words::ago($created) . ')',
            ]);

        return $view->grid(
            2,
            $paymentId > 0 ? Links::to($admin, '💳 پرداخت ' . Fmt::id($paymentId), Links::PAYMENT, Nav::SHOW, $paymentId) : null,
            $orderId > 0 ? Links::to($admin, '🛒 سفارش ' . Fmt::id($orderId), Links::ORDER, Nav::SHOW, $orderId) : null,
            Links::customer($admin, $referrer, 'معرف: ' . Words::name($referrer)),
            Links::customer($admin, $customer, 'زیرمجموعه: ' . Words::name($customer)),
        )->back(CommissionsScreen::ID);
    }
}
