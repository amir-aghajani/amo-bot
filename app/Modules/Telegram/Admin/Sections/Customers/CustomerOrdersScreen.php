<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Admin\Sections\Customers;

use App\Modules\Telegram\Admin\Sections\Sales\Sales;
use App\Modules\Telegram\Admin\Ui\Button;
use App\Modules\Telegram\Admin\Ui\Fmt;
use App\Modules\Telegram\Admin\Ui\Icons;

/**
 * «🛒 سفارش‌ها» of a customer's page: their orders, newest first, each a button — its state, number, what it was for, the
 * plan and the amount — opening the order's screen (`orv`).
 */
final class CustomerOrdersScreen extends CustomerRowsScreen
{
    public const ID = 'uslo';

    public const TITLE = Icons::ORDERS . ' سفارش‌ها';

    protected function screen(): string
    {
        return self::ID;
    }

    protected function title(): string
    {
        return self::TITLE;
    }

    protected function endpoint(): string
    {
        return '/orders';
    }

    protected function key(): string
    {
        return 'orders';
    }

    protected function noun(): string
    {
        return 'سفارش';
    }

    protected function empty(): string
    {
        return 'هنوز سفارشی ثبت نکرده است. خریدها، تمدیدها و شارژهای این مشتری این‌جا می‌آیند.';
    }

    protected function legend(): string
    {
        return implode(' · ', Sales::ORDER_STATUS);
    }

    protected function item(array $row): Button
    {
        return $this->open(
            Sales::mark(Sales::ORDER_STATUS, $row['status'] ?? '') . ' ' . implode(' · ', array_filter([
                Fmt::id(Customer::int($row, 'id')),
                Sales::word(Sales::ORDER_TYPE, $row['type'] ?? ''),
                // The plan cut, so the amount stays whole on the button.
                Customer::text($row, 'package_name') === null ? null : Fmt::cut(Customer::text($row, 'package_name'), 16),
                Fmt::money(Customer::money($row['amount'] ?? null)),
            ])),
            'orv',
            Customer::int($row, 'id'),
        );
    }
}
